<?php
/*
 * @version $Id$
 -------------------------------------------------------------------------
 GLPI - Gestionnaire Libre de Parc Informatique
 Copyright (C) 2003-2012 by the INDEPNET Development Team.

 http://indepnet.net/   http://glpi-project.org
 -------------------------------------------------------------------------

 LICENSE

 This file is part of GLPI.

 GLPI is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 2 of the License, or
 (at your option) any later version.

 GLPI is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with GLPI. If not, see <http://www.gnu.org/licenses/>.
 --------------------------------------------------------------------------
 */

// ----------------------------------------------------------------------
// Original Author of file:
// Purpose of file:
// ----------------------------------------------------------------------

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

/**
 *  Computer class
**/
class Computer extends CommonDBTM {

   // From CommonDBTM
   public $dohistory = true;

   protected $forward_entity_to = array('ComputerDisk','ComputerVirtualMachine', 'Infocom',
                                        'NetworkPort', 'Ocslink', 'ReservationItem');
   // Specific ones
   ///Device container - format $device = array(ID,"device type","ID in device table","specificity value")
   var $devices = array();


   /**
    * Name of the type
    *
    * @param $nb  integer  number of item in the type (default 0)
   **/
   static function getTypeName($nb=0) {
      return _n('Computer', 'Computers', $nb);
   }


   function canCreate() {
      return Session::haveRight('computer', 'w');
   }


   function canView() {
      return Session::haveRight('computer', 'r');
   }


   /**
    * @see inc/CommonGLPI::defineTabs()
   **/
   function defineTabs($options=array()) {

      $ong = array();
      $this->addStandardTab('DeviceProcessor', $ong, $options); // All devices : use one to define tab
      $this->addStandardTab('ComputerDisk', $ong, $options);
      $this->addStandardTab('Computer_SoftwareVersion', $ong, $options);
      $this->addStandardTab('Computer_Item', $ong, $options);
      $this->addStandardTab('NetworkPort', $ong, $options);
      $this->addStandardTab('Infocom', $ong, $options);
      $this->addStandardTab('Contract_Item', $ong, $options);
      $this->addStandardTab('Document', $ong, $options);
      $this->addStandardTab('ComputerVirtualMachine', $ong, $options);
      $this->addStandardTab('RegistryKey', $ong, $options);
      $this->addStandardTab('Ticket', $ong, $options);
      $this->addStandardTab('Item_Problem', $ong, $options);
      $this->addStandardTab('Link', $ong, $options);
      $this->addStandardTab('Note', $ong, $options);
      $this->addStandardTab('Reservation', $ong, $options);
      $this->addStandardTab('Log', $ong, $options);
      $this->addStandardTab('OcsLink', $ong, $options);

      return $ong;
   }


   function post_restoreItem() {

      $comp_softvers = new Computer_SoftwareVersion();
      $comp_softvers->updateDatasForComputer($this->fields['id']);
   }


   function post_deleteItem() {

      $comp_softvers = new Computer_SoftwareVersion();
      $comp_softvers->updateDatasForComputer($this->fields['id']);
   }


   /**
    * @see inc/CommonDBTM::post_updateItem()
   **/
   function post_updateItem($history=1) {
      global $DB, $CFG_GLPI;

      // Manage changes for OCS if more than 1 element (date_mod)
      // Need dohistory==1 if dohistory==2 no locking fields
      if ($this->fields["is_ocs_import"]
          && ($history == 1)
          && (count($this->updates) > 1)) {
         OcsServer::mergeOcsArray($this->fields["id"], $this->updates, "computer_update");
      }

      if (isset($this->input["_auto_update_ocs"])) {
         $query = "UPDATE `glpi_ocslinks`
                   SET `use_auto_update` = '".$this->input["_auto_update_ocs"]."'
                   WHERE `computers_id` = '".$this->input["id"]."'";
         $DB->query($query);
      }

      for ($i=0 ; $i<count($this->updates) ; $i++) {
         // Update contact of attached items
         if ((($this->updates[$i] == "contact") || ($this->updates[$i] == "contact_num"))
             && $CFG_GLPI["is_contact_autoupdate"]) {

            $items = array('Monitor', 'Peripheral', 'Phone', 'Printer');

            $update_done = false;
            $updates3[0] = "contact";
            $updates3[1] = "contact_num";

            foreach ($items as $t) {
               $query = "SELECT *
                         FROM `glpi_computers_items`
                         WHERE `computers_id` = '".$this->fields["id"]."'
                               AND `itemtype` = '".$t."'";
               if ($result = $DB->query($query)) {
                  $resultnum = $DB->numrows($result);
                  $item      = new $t();
                  if ($resultnum > 0) {
                     for ($j=0 ; $j<$resultnum ; $j++) {
                        $tID = $DB->result($result, $j, "items_id");
                        $item->getFromDB($tID);
                        if (!$item->getField('is_global')) {
                           if ($item->getField('contact') != $this->fields['contact']
                               || ($item->getField('contact_num') != $this->fields['contact_num'])) {

                              $tmp["id"]          = $item->getField('id');
                              $tmp['contact']     = $this->fields['contact'];
                              $tmp['contact_num'] = $this->fields['contact_num'];
                              $item->update($tmp);
                              $update_done        = true;
                           }
                        }
                     }
                  }
               }
            }

            if ($update_done) {
               Session::addMessageAfterRedirect(
                  __('Alternate username updated. The connected items have been updated using this alternate username.'),
                  true);
            }
         }

         // Update users and groups of attached items
         if ((($this->updates[$i] == "users_id")
              && ($this->fields["users_id"] != 0)
              && $CFG_GLPI["is_user_autoupdate"])
             || (($this->updates[$i] == "groups_id")
                 && ($this->fields["groups_id"] != 0)
                 && $CFG_GLPI["is_group_autoupdate"])) {

            $items = array('Monitor', 'Peripheral', 'Phone', 'Printer');

            $update_done = false;
            $updates4[0] = "users_id";
            $updates4[1] = "groups_id";

            foreach ($items as $t) {
               $query = "SELECT *
                         FROM `glpi_computers_items`
                         WHERE `computers_id` = '".$this->fields["id"]."'
                               AND `itemtype` = '".$t."'";

               if ($result = $DB->query($query)) {
                  $resultnum = $DB->numrows($result);
                  $item      = new $t();
                  if ($resultnum > 0) {
                     for ($j=0 ; $j<$resultnum ; $j++) {
                        $tID = $DB->result($result, $j, "items_id");
                        $item->getFromDB($tID);
                        if (!$item->getField('is_global')) {
                           if (($item->getField('users_id') != $this->fields["users_id"])
                               || ($item->getField('groups_id') != $this->fields["groups_id"])) {

                              $tmp["id"] = $item->getField('id');

                              if ($CFG_GLPI["is_user_autoupdate"]) {
                                 $tmp["users_id"] = $this->fields["users_id"];
                              }
                              if ($CFG_GLPI["is_group_autoupdate"]) {
                                 $tmp["groups_id"] = $this->fields["groups_id"];
                              }
                              $item->update($tmp);
                              $update_done = true;
                           }
                        }
                     }
                  }
               }
            }
            if ($update_done) {
               Session::addMessageAfterRedirect(
                  __('User or group updated. The connected items have been moved in the same values.'),
                  true);
            }
         }

         // Update state of attached items
         if (($this->updates[$i] == "states_id")
             && ($CFG_GLPI["state_autoupdate_mode"] < 0)) {
            $items       = array('Monitor', 'Peripheral', 'Phone', 'Printer');
            $update_done = false;

            foreach ($items as $t) {
               $query = "SELECT *
                         FROM `glpi_computers_items`
                         WHERE `computers_id` = '".$this->fields["id"]."'
                               AND `itemtype` = '".$t."'";

               if ($result = $DB->query($query)) {
                  $resultnum = $DB->numrows($result);
                  $item      = new $t();

                  if ($resultnum > 0) {
                     for ($j=0 ; $j<$resultnum ; $j++) {
                        $tID = $DB->result($result, $j, "items_id");
                        $item->getFromDB($tID);
                        if (!$item->getField('is_global')) {
                           if ($item->getField('states_id') != $this->fields["states_id"]) {
                              $tmp["id"]        = $item->getField('id');
                              $tmp["states_id"] = $this->fields["states_id"];
                              $item->update($tmp);
                              $update_done      = true;
                           }
                        }
                     }
                  }
               }
            }
            if ($update_done) {
               Session::addMessageAfterRedirect(
                     __('Status updated. The connected items have been updated using this status.'),
                     true);
            }
         }

         // Update loction of attached items
         if (($this->updates[$i] == "locations_id")
             && ($this->fields["locations_id"] != 0)
             && $CFG_GLPI["is_location_autoupdate"]) {

            $items       = array('Monitor', 'Peripheral', 'Phone', 'Printer');
            $update_done = false;
            $updates2[0] = "locations_id";

            foreach ($items as $t) {
               $query = "SELECT *
                         FROM `glpi_computers_items`
                         WHERE `computers_id` = '".$this->fields["id"]."'
                               AND `itemtype` = '".$t."'";

               if ($result = $DB->query($query)) {
                  $resultnum = $DB->numrows($result);
                  $item      = new $t();

                  if ($resultnum > 0) {
                     for ($j=0 ; $j<$resultnum ; $j++) {
                        $tID = $DB->result($result, $j, "items_id");
                        $item->getFromDB($tID);
                        if (!$item->getField('is_global')) {
                           if ($item->getField('locations_id') != $this->fields["locations_id"]) {
                              $tmp["id"]           = $item->getField('id');
                              $tmp["locations_id"] = $this->fields["locations_id"];
                              $item->update($tmp);
                              $update_done         = true;
                           }
                        }
                     }
                  }
               }
            }
            if ($update_done) {
               Session::addMessageAfterRedirect(
                  __('Location updated. The connected items have been moved in the same location.'),
                  true);
            }
         }
      }
   }


   /**
    * @see inc/CommonDBTM::prepareInputForAdd()
   **/
   function prepareInputForAdd($input) {

      if (isset($input["id"]) && ($input["id"] > 0)) {
         $input["_oldID"] = $input["id"];
      }
      unset($input['id']);
      unset($input['withtemplate']);

      return $input;
   }


   function post_addItem() {
      global $DB;

      // Manage add from template
      if (isset($this->input["_oldID"])) {
         // ADD Devices
         $compdev = new Computer_Device();
         $compdev->cloneComputer($this->input["_oldID"], $this->fields['id']);

         // ADD Infocoms
         $ic = new Infocom();
         $ic->cloneItem($this->getType(), $this->input["_oldID"], $this->fields['id']);

         // ADD volumes
         $query  = "SELECT `id`
                    FROM `glpi_computerdisks`
                    WHERE `computers_id` = '".$this->input["_oldID"]."'";
         $result = $DB->query($query);
         if ($DB->numrows($result) > 0) {
            while ($data = $DB->fetch_assoc($result)) {
               $disk = new ComputerDisk();
               $disk->getfromDB($data['id']);
               unset($disk->fields["id"]);
               $disk->fields["computers_id"] = $this->fields['id'];
               $disk->addToDB();
            }
         }

         // ADD software
         $inst = new Computer_SoftwareVersion();
         $inst->cloneComputer($this->input["_oldID"], $this->fields['id']);

         $inst = new Computer_SoftwareLicense();
         $inst->cloneComputer($this->input["_oldID"], $this->fields['id']);

         // ADD Contract
         $query  = "SELECT `contracts_id`
                    FROM `glpi_contracts_items`
                    WHERE `items_id` = '".$this->input["_oldID"]."'
                          AND `itemtype` = '".$this->getType()."';";
         $result = $DB->query($query);
         if ($DB->numrows($result) > 0) {
            $contractitem = new Contract_Item();
            while ($data = $DB->fetch_assoc($result)) {
               $contractitem->add(array('contracts_id' => $data["contracts_id"],
                                        'itemtype'     => $this->getType(),
                                        'items_id'     => $this->fields['id']));
            }
         }

         // ADD Documents
         $query  = "SELECT `documents_id`
                    FROM `glpi_documents_items`
                    WHERE `items_id` = '".$this->input["_oldID"]."'
                          AND `itemtype` = '".$this->getType()."';";
         $result = $DB->query($query);
         if ($DB->numrows($result) > 0) {
            $docitem = new Document_Item();
            while ($data = $DB->fetch_assoc($result)) {
               $docitem->add(array('documents_id' => $data["documents_id"],
                                   'itemtype'     => $this->getType(),
                                   'items_id'     => $this->fields['id']));
            }
         }

         // ADD Ports
         NetworkPort::cloneItem($this->getType(), $this->input["_oldID"], $this->fields['id']);

         // Add connected devices
         $query  = "SELECT *
                    FROM `glpi_computers_items`
                    WHERE `computers_id` = '".$this->input["_oldID"]."';";
         $result = $DB->query($query);

         if ($DB->numrows($result) > 0) {
            $conn = new Computer_Item();
            while ($data=$DB->fetch_assoc($result)) {
               $conn->add(array('computers_id' => $this->fields['id'],
                                'itemtype'     => $data["itemtype"],
                                'items_id'     => $data["items_id"]));
            }
         }
      }
   }


   function cleanDBonPurge() {
      global $DB;

      $query  = "DELETE
                 FROM `glpi_computers_softwareversions`
                 WHERE `computers_id` = '".$this->fields['id']."'";
      $result = $DB->query($query);

      $query = "SELECT `id`
                FROM `glpi_computers_items`
                WHERE `computers_id` = '".$this->fields['id']."'";

      if ($result = $DB->query($query)) {
         if ($DB->numrows($result) > 0) {
            $conn = new Computer_Item();
            while ($data = $DB->fetch_assoc($result)) {
               $data['_no_auto_action'] = true;
               $conn->delete($data);
            }
         }
      }

      $query  = "DELETE
                 FROM `glpi_registrykeys`
                 WHERE `computers_id` = '".$this->fields['id']."'";
      $result = $DB->query($query);

      $compdev = new Computer_Device();
      $compdev->cleanDBonItemDelete('Computer', $this->fields['id']);

      $query  = "DELETE
                 FROM `glpi_ocslinks`
                 WHERE `computers_id` = '".$this->fields['id']."'";
      $result = $DB->query($query);

      $disk = new ComputerDisk();
      $disk->cleanDBonItemDelete('Computer', $this->fields['id']);

      $vm = new ComputerVirtualMachine();
      $vm->cleanDBonItemDelete('Computer', $this->fields['id']);
   }


   /**
    * Print the computer form
    *
    * @param $ID        integer ID of the item
    * @param $options   array
    *     - target for the Form
    *     - withtemplate template or basic computer
    *
    *@return Nothing (display)
   **/
   function showForm($ID, $options=array()) {
      global $CFG_GLPI, $DB;

      $this->initForm($ID, $options);
      $this->showTabs($options);
      $this->showFormHeader($options);

      echo "<tr class='tab_bg_1'>";
      //TRANS: %1$s is a string, %2$s a second one without spaces between them : to change for RTL
      echo "<td>".sprintf(__('%1$s%2$s'),__('Name'),
                          (isset($options['withtemplate']) && $options['withtemplate']?"*":"")).
           "</td>";
      echo "<td>";
      $objectName = autoName($this->fields["name"], "name",
                             (isset($options['withtemplate']) && ( $options['withtemplate']== 2)),
                             $this->getType(), $this->fields["entities_id"]);
      Html::autocompletionTextField($this, 'name', array('value' => $objectName));
      echo "</td>";
      echo "<td>".__('Status')."</td>";
      echo "<td>";
      Dropdown::show('State', array('value' => $this->fields["states_id"]));
      echo "</td></tr>\n";

      echo "<tr class='tab_bg_1'>";
      echo "<td>".__('Location')."</td>";
      echo "<td>";
      Dropdown::show('Location', array('value'  => $this->fields["locations_id"],
                                       'entity' => $this->fields["entities_id"]));
      echo "</td>";
      echo "<td>".__('Type')."</td>";
      echo "<td>";
      Dropdown::show('ComputerType', array('value' => $this->fields["computertypes_id"]));
      echo "</td></tr>\n";

      echo "<tr class='tab_bg_1'>";
      echo "<td>".__('Technician in charge of the hardware')."</td>";
      echo "<td>";
      User::dropdown(array('name'   => 'users_id_tech',
                           'value'  => $this->fields["users_id_tech"],
                           'right'  => 'interface',
                           'entity' => $this->fields["entities_id"]));
      echo "</td>";
      echo "<td>".__('Manufacturer')."</td>";
      echo "<td>";
      Dropdown::show('Manufacturer', array('value' => $this->fields["manufacturers_id"]));
      echo "</td></tr>\n";

      echo "<tr class='tab_bg_1'>";
      echo "<td>".__('Group in charge of the hardware')."</td>";
      echo "<td>";
      Dropdown::show('Group', array('name'      => 'groups_id_tech',
                                    'value'     => $this->fields['groups_id_tech'],
                                    'entity'    => $this->fields['entities_id'],
                                    'condition' => '`is_assign`'));

      echo "</td>";
      echo "<td>".__('Model')."</td>";
      echo "<td>";
      Dropdown::show('ComputerModel', array('value' => $this->fields["computermodels_id"]));
      echo "</td></tr>\n";

      echo "<tr class='tab_bg_1'>";
      echo "<td>".__('Alternate username number')."</td>";
      echo "<td >";
      Html::autocompletionTextField($this,'contact_num');
      echo "</td>";
      echo "<td>".__('Serial number')."</td>";
      echo "<td >";
      Html::autocompletionTextField($this,'serial');
      echo "</td></tr>\n";

      echo "<tr class='tab_bg_1'>";
      echo "<td>".__('Alternate username')."</td>";
      echo "<td>";
      Html::autocompletionTextField($this,'contact');
      echo "</td>";
      echo "<td>".sprintf(__('%1$s%2$s'), __('Inventory number'),
                          (isset($options['withtemplate']) && $options['withtemplate']?"*":"")).
           "</td>";
      echo "<td>";
      $objectName = autoName($this->fields["otherserial"], "otherserial",
                             (isset($options['withtemplate']) && ($options['withtemplate'] == 2)),
                             $this->getType(), $this->fields["entities_id"]);
      Html::autocompletionTextField($this, 'otherserial', array('value' => $objectName));
      echo "</td></tr>\n";

      echo "<tr class='tab_bg_1'>";
      echo "<td>".__('User')."</td>";
      echo "<td>";
      User::dropdown(array('value'  => $this->fields["users_id"],
                           'entity' => $this->fields["entities_id"],
                           'right'  => 'all'));
      echo "</td>";
      echo "<td>".__('Network')."</td>";
      echo "<td>";
      Dropdown::show('Network', array('value' => $this->fields["networks_id"]));
      echo "</td></tr>\n";

      echo "<tr class='tab_bg_1'>";
      echo "<td>".__('Group')."</td>";
      echo "<td>";
      Dropdown::show('Group', array('value'     => $this->fields["groups_id"],
                                    'entity'    => $this->fields["entities_id"],
                                    'condition' => '`is_itemgroup`'));

      echo "</td>";
      
      // Get OCS Datas :
      $dataocs = array();
      $rowspan = 10;
      $ocs_show = false;
      
      if (!empty($ID)
          && $this->fields["is_ocs_import"]
          && Session::haveRight("view_ocsng","r")) {

         $query = "SELECT *
                   FROM `glpi_ocslinks`
                   WHERE `computers_id` = '$ID'";

         $result = $DB->query($query);
         if ($DB->numrows($result)==1) {
            $dataocs = $DB->fetch_array($result);
         }
      }      
      
      if (count($dataocs)) {
         $ocs_config = OcsServer::getConfig(OcsServer::getByMachineID($ID));
         $ocs_show = true;
         $rowspan -=4;    
      }
            
      echo "<td rowspan='$rowspan'>".__('Comments')."</td>";
      echo "<td rowspan='$rowspan' class='middle'>";
      echo "<textarea cols='45' rows='".($rowspan+3)."' name='comment' >".$this->fields["comment"]."</textarea>";
      echo "</td></tr>\n";

      echo "<tr class='tab_bg_1'>";
      echo "<td>".__('Domain')."</td>";
      echo "<td >";
      Dropdown::show('Domain', array('value' => $this->fields["domains_id"]));
      echo "</td></tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<td>".__('Operating system')."</td>";
      echo "<td>";
      Dropdown::show('OperatingSystem', array('value' => $this->fields["operatingsystems_id"]));
      echo "</td></tr>\n";

      echo "<tr class='tab_bg_1'>";
      echo "<td>".__('Service Pack')."</td>";
      echo "<td >";
      Dropdown::show('OperatingSystemServicePack',
                     array('value' => $this->fields["operatingsystemservicepacks_id"]));
      echo "</td></tr>\n";

      echo "<tr class='tab_bg_1'>";
      echo "<td>".__('Version of the operating system')."</td>";
      echo "<td >";
      Dropdown::show('OperatingSystemVersion',
                     array('value' => $this->fields["operatingsystemversions_id"]));
      echo "</td></tr>\n";

      echo "<tr class='tab_bg_1'>";
      echo "<td>".__('Product ID of the operating system')."</td>";
      echo "<td >";
      Html::autocompletionTextField($this, 'os_licenseid');
      echo "</td></tr>\n";

      echo "<tr class='tab_bg_1'>";
      echo "<td>".__('Serial of the operating system')."</td>";
      echo "<td >";
      Html::autocompletionTextField($this, 'os_license_number');
      echo "</td>";
      ///TODO create get_inventory_plugin_information_title and display : manage rowspan based on datas get
      if ($ocs_show) {
         echo "<th colspan='2'>";
         if (Session::haveRight("ocsng","w") && $ocs_config["ocs_url"] != '') {
            echo OcsServer::getComputerLinkToOcsConsole (OcsServer::getByMachineID($ID),
                                                               $dataocs["ocsid"],
                                                               _e('OCSNG link'));
         } else {
            _e('OCSNG link');
         }
         echo "</th>";
      }
            
      echo "</tr>\n";

      echo "<tr class='tab_bg_1'>";
      echo "<td>".__('UUID')."</td>";
      echo "<td >";
      Html::autocompletionTextField($this, 'uuid');
      echo "</td>";
      ///TODO create get_inventory_plugin_information and get information to display
      if ($ocs_show) {
         echo "<td colspan='2' rowspan='3'>";
         echo "<table class='format'><tr><td>";
         echo __('Last OCSNG inventory date')."</td><td>".Html::convDateTime($dataocs["last_ocs_update"]);
         echo "</td></tr><tr><td>";
         echo __('Import date in GLPI')."</td><td> ".Html::convDateTime($dataocs["last_update"]);
         echo "</td></tr>";
         echo "<tr><td>".__('Server')."</td><td>";
         if (Session::haveRight("ocsng","r")) {
            echo "<a href='".$CFG_GLPI["root_doc"]."/front/ocsserver.form.php?id="
                  .OcsServer::getByMachineID($ID)."'>".OcsServer::getServerNameByID($ID)."</a>";
         } else {
            echo OcsServer::getServerNameByID($ID);
         }
         
         echo "</td></tr>";
         
         if ($dataocs["ocs_agent_version"] != NULL) {
            echo "<tr><td>".__('Agent')."</td><td>".$dataocs["ocs_agent_version"].'</td></tr>';
         }
         if (Session::haveRight("sync_ocsng","w")) {
            echo "</tr><td>".__('Auto update OCSNG')."</td>";
            echo "<td>";
            Dropdown::showYesNo("_auto_update_ocs",$dataocs["use_auto_update"]);
            echo "</td></tr>";
         }     
         
         echo "</table>";
         echo "</td>";
      }        
      echo "</tr>\n";


      echo "<tr class='tab_bg_1'>";
      echo "<td>";
      if ((!isset($options['withtemplate']) || ($options['withtemplate'] == 0))
          && !empty($this->fields['template_name'])) {
         echo "<span class='small_space'>";
         printf(__('Created from the template %s'), $this->fields['template_name']);
         echo "</span>";
      } else {
         echo "&nbsp;";
      }
      echo "</td><td>";
      if (isset($options['withtemplate']) && $options['withtemplate']) {
         //TRANS: %s is the datetime of insertion
         printf(__('Created on %s'), Html::convDateTime($_SESSION["glpi_currenttime"]));
      } else {
         //TRANS: %s is the datetime of update
         printf(__('Last update on %s'), Html::convDateTime($this->fields["date_mod"]));
      }
      echo "</td></tr>\n";

      echo "<tr class='tab_bg_1'>";
      echo "<td>".__('Update Source')."</td>";
      echo "<td >";
      Dropdown::show('AutoUpdateSystem', array('value' => $this->fields["autoupdatesystems_id"]));
      echo "</td></tr>";

      $this->showFormButtons($options);
      $this->addDivForTabs();

      return true;
   }


   /**
    * Return the SQL command to retrieve linked object
    *
    * @return a SQL command which return a set of (itemtype, items_id)
    */
   function getSelectLinkedItem() {

      return "SELECT `itemtype`, `items_id`
              FROM `glpi_computers_items`
              WHERE `computers_id` = '" . $this->fields['id']."'";
   }


   function getSearchOptions() {
      global $CFG_GLPI;

      $tab                       = array();
      $tab['common']             = __('Characteristics');

      $tab[1]['table']           = $this->getTable();
      $tab[1]['field']           = 'name';
      $tab[1]['name']            = __('Name');
      $tab[1]['datatype']        = 'itemlink';
      $tab[1]['itemlink_type']   = $this->getType();
      $tab[1]['massiveaction']   = false; // implicit key==1

      $tab[2]['table']           = $this->getTable();
      $tab[2]['field']           = 'id';
      $tab[2]['name']            = __('ID');
      $tab[2]['massiveaction']   = false; // implicit field is id

      $tab += Location::getSearchOptionsToAdd();

      $tab[4]['table']           = 'glpi_computertypes';
      $tab[4]['field']           = 'name';
      $tab[4]['name']            = __('Type');

      $tab[40]['table']          = 'glpi_computermodels';
      $tab[40]['field']          = 'name';
      $tab[40]['name']           = __('Model');

      $tab[31]['table']          = 'glpi_states';
      $tab[31]['field']          = 'completename';
      $tab[31]['name']           = __('Status');

      $tab[45]['table']          = 'glpi_operatingsystems';
      $tab[45]['field']          = 'name';
      $tab[45]['name']           = __('Operating system');

      $tab[46]['table']          = 'glpi_operatingsystemversions';
      $tab[46]['field']          = 'name';
      $tab[46]['name']           = __('Version of the operating system');

      $tab[41]['table']          = 'glpi_operatingsystemservicepacks';
      $tab[41]['field']          = 'name';
      $tab[41]['name']           = __('Service Pack');

      $tab[42]['table']          = 'glpi_autoupdatesystems';
      $tab[42]['field']          = 'name';
      $tab[42]['name']           = __('Update Source');

      $tab[43]['table']          = $this->getTable();
      $tab[43]['field']          = 'os_license_number';
      $tab[43]['name']           = __('Serial of the operating system');

      $tab[44]['table']          = $this->getTable();
      $tab[44]['field']          = 'os_licenseid';
      $tab[44]['name']           = __('Product ID of the operating system');

      $tab[47]['table']          = $this->getTable();
      $tab[47]['field']          = 'uuid';
      $tab[47]['name']           = __('UUID');


      $tab[5]['table']           = $this->getTable();
      $tab[5]['field']           = 'serial';
      $tab[5]['name']            = __('Serial number');
      $tab[5]['datatype']        = 'string';

      $tab[6]['table']           = $this->getTable();
      $tab[6]['field']           = 'otherserial';
      $tab[6]['name']            = __('Inventory number');
      $tab[6]['datatype']        = 'string';

      $tab[16]['table']          = $this->getTable();
      $tab[16]['field']          = 'comment';
      $tab[16]['name']           = __('Comments');
      $tab[16]['datatype']       = 'text';

      $tab[90]['table']          = $this->getTable();
      $tab[90]['field']          = 'notepad';
      $tab[90]['name']           = __('Notes');
      $tab[90]['massiveaction']  = false;

      $tab[17]['table']          = $this->getTable();
      $tab[17]['field']          = 'contact';
      $tab[17]['name']           = __('Alternate username');
      $tab[17]['datatype']       = 'string';

      $tab[18]['table']          = $this->getTable();
      $tab[18]['field']          = 'contact_num';
      $tab[18]['name']           = __('Alternate username number');
      $tab[18]['datatype']       = 'string';

      $tab[70]['table']          = 'glpi_users';
      $tab[70]['field']          = 'name';
      $tab[70]['name']           = __('User');

      $tab[71]['table']          = 'glpi_groups';
      $tab[71]['field']          = 'completename';
      $tab[71]['name']           = __('Group');
      $tab[71]['condition']      = '`is_itemgroup`';

      $tab[19]['table']          = $this->getTable();
      $tab[19]['field']          = 'date_mod';
      $tab[19]['name']           = __('Last update');
      $tab[19]['datatype']       = 'datetime';
      $tab[19]['massiveaction']  = false;

      $tab[32]['table']          = 'glpi_networks';
      $tab[32]['field']          = 'name';
      $tab[32]['name']           = __('Network');

      $tab[33]['table']          = 'glpi_domains';
      $tab[33]['field']          = 'name';
      $tab[33]['name']           = __('Domain');

      $tab[23]['table']          = 'glpi_manufacturers';
      $tab[23]['field']          = 'name';
      $tab[23]['name']           = __('Manufacturer');

      $tab[24]['table']          = 'glpi_users';
      $tab[24]['field']          = 'name';
      $tab[24]['linkfield']      = 'users_id_tech';
      $tab[24]['name']           = __('Technician in charge of the hardware');

      $tab[49]['table']          = 'glpi_groups';
      $tab[49]['field']          = 'completename';
      $tab[49]['linkfield']      = 'groups_id_tech';
      $tab[49]['name']           = __('Group in charge of the hardware');
      $tab[49]['condition']      = '`is_assign`';

      $tab[80]['table']          = 'glpi_entities';
      $tab[80]['field']          = 'completename';
      $tab[80]['name']           = __('Entity');


      $tab['periph']             = _n('Component', 'Components', 2);

      $tab[7]['table']           = 'glpi_deviceprocessors';
      $tab[7]['field']           = 'designation';
      $tab[7]['name']            = __('Processor');
      $tab[7]['forcegroupby']    = true;
      $tab[7]['usehaving']       = true;
      $tab[7]['massiveaction']   = false;
      $tab[7]['joinparams']      = array('beforejoin'
                                          => array('table'      => 'glpi_computers_deviceprocessors',
                                                   'joinparams' => array('jointype' => 'child')));


      $tab[36]['table']          = 'glpi_computers_deviceprocessors';
      $tab[36]['field']          = 'specificity';
      $tab[36]['name']           = __('Processeur frequency');
      $tab[36]['unit']           = __('MHz');
      $tab[36]['forcegroupby']   = true;
      $tab[36]['usehaving']      = true;
      $tab[36]['datatype']       = 'number';
      $tab[36]['width']          = 100;
      $tab[36]['massiveaction']  = false;
      $tab[36]['joinparams']     = array('jointype' => 'child');

      $tab[10]['table']          = 'glpi_devicememories';
      $tab[10]['field']          = 'designation';
      $tab[10]['name']           = __('Memory type');
      $tab[10]['forcegroupby']   = true;
      $tab[10]['usehaving']      = true;
      $tab[10]['massiveaction']  = false;
      $tab[10]['joinparams']     = array('beforejoin'
                                          => array('table'      => 'glpi_computers_devicememories',
                                                   'joinparams' => array('jointype' => 'child')));

      $tab[35]['table']          = 'glpi_computers_devicememories';
      $tab[35]['field']          = 'specificity';
      $tab[35]['name']           = sprintf(__('%1$s (%2$s)'),__('Memory'),__('Mio'));
      $tab[35]['forcegroupby']   = true;
      $tab[35]['usehaving']      = true;
      $tab[35]['datatype']       = 'number';
      $tab[35]['width']          = 100;
      $tab[35]['massiveaction']  = false;
      $tab[35]['joinparams']     = array('jointype' => 'child');


      $tab[11]['table']          = 'glpi_devicenetworkcards';
      $tab[11]['field']          = 'designation';
      $tab[11]['name']           = _n('Network interface', 'Network interfaces', 1);
      $tab[11]['forcegroupby']   = true;
      $tab[11]['massiveaction']  = false;
      $tab[11]['joinparams']     = array('beforejoin'
                                          => array('table'      => 'glpi_computers_devicenetworkcards',
                                                   'joinparams' => array('jointype' => 'child')));

      $tab[12]['table']          = 'glpi_devicesoundcards';
      $tab[12]['field']          = 'designation';
      $tab[12]['name']           = __('Soundcard');
      $tab[12]['forcegroupby']   = true;
      $tab[12]['massiveaction']  = false;
      $tab[12]['joinparams']     = array('beforejoin'
                                          => array('table'      => 'glpi_computers_devicesoundcards',
                                                   'joinparams' => array('jointype' => 'child')));

      $tab[13]['table']          = 'glpi_devicegraphiccards';
      $tab[13]['field']          = 'designation';
      $tab[13]['name']           = __('Graphics card');
      $tab[13]['forcegroupby']   = true;
      $tab[13]['massiveaction']  = false;
      $tab[13]['joinparams']     = array('beforejoin'
                                          => array('table'      => 'glpi_computers_devicegraphiccards',
                                                   'joinparams' => array('jointype' => 'child')));

      $tab[14]['table']          = 'glpi_devicemotherboards';
      $tab[14]['field']          = 'designation';
      $tab[14]['name']           = __('System board');
      $tab[14]['forcegroupby']   = true;
      $tab[14]['massiveaction']  = false;
      $tab[14]['joinparams']     = array('beforejoin'
                                          => array('table'      => 'glpi_computers_devicemotherboards',
                                                   'joinparams' => array('jointype' => 'child')));


      $tab[15]['table']          = 'glpi_deviceharddrives';
      $tab[15]['field']          = 'designation';
      $tab[15]['name']           = __('Hard drive type');
      $tab[15]['forcegroupby']   = true;
      $tab[15]['usehaving']      = true;
      $tab[15]['massiveaction']  = false;
      $tab[15]['joinparams']     = array('beforejoin'
                                          => array('table'      => 'glpi_computers_deviceharddrives',
                                                   'joinparams' => array('jointype' => 'child')));

      $tab[34]['table']          = 'glpi_computers_deviceharddrives';
      $tab[34]['field']          = 'specificity';
      $tab[34]['name']           = __('Hard drive size');
      $tab[34]['forcegroupby']   = true;
      $tab[34]['usehaving']      = true;
      $tab[34]['datatype']       = 'number';
      $tab[34]['width']          = 1000;
      $tab[34]['massiveaction']  = false;
      $tab[34]['joinparams']     = array('jointype' => 'child');


      $tab[39]['table']          = 'glpi_devicepowersupplies';
      $tab[39]['field']          = 'designation';
      $tab[39]['name']           = __('Power supply');
      $tab[39]['forcegroupby']   = true;
      $tab[39]['usehaving']      = true;
      $tab[39]['massiveaction']  = false;
      $tab[39]['joinparams']     = array('beforejoin'
                                          => array('table'      => 'glpi_computers_devicepowersupplies',
                                                   'joinparams' => array('jointype' => 'child')));

      $tab['disk']               = _n('Volume', 'Volumes', 2);

      $tab[156]['table']         = 'glpi_computerdisks';
      $tab[156]['field']         = 'name';
      $tab[156]['name']          = __('Volume');
      $tab[156]['forcegroupby']  = true;
      $tab[156]['massiveaction'] = false;
      $tab[156]['joinparams']    = array('jointype' => 'child');

      $tab[150]['table']         = 'glpi_computerdisks';
      $tab[150]['field']         = 'totalsize';
      $tab[150]['name']          = __('Global size');
      $tab[150]['forcegroupby']  = true;
      $tab[150]['usehaving']     = true;
      $tab[150]['datatype']      = 'number';
      $tab[150]['width']         = 1000;
      $tab[150]['massiveaction'] = false;
      $tab[150]['joinparams']    = array('jointype' => 'child');

      $tab[151]['table']         = 'glpi_computerdisks';
      $tab[151]['field']         = 'freesize';
      $tab[151]['name']          = __('Free size');
      $tab[151]['forcegroupby']  = true;
      $tab[151]['datatype']      = 'number';
      $tab[151]['width']         = 1000;
      $tab[151]['massiveaction'] = false;
      $tab[151]['joinparams']    = array('jointype' => 'child');

      $tab[152]['table']         = 'glpi_computerdisks';
      $tab[152]['field']         = 'freepercent';
      $tab[152]['name']          = __('Free percentage');
      $tab[152]['forcegroupby']  = true;
      $tab[152]['datatype']      = 'decimal';
      $tab[152]['width']         = 2;
      $tab[152]['computation']   = "ROUND(100*TABLE.freesize/TABLE.totalsize)";
      $tab[152]['unit']          = '%';
      $tab[152]['massiveaction'] = false;
      $tab[152]['joinparams']    = array('jointype' => 'child');

      $tab[153]['table']         = 'glpi_computerdisks';
      $tab[153]['field']         = 'mountpoint';
      $tab[153]['name']          = __('Mount point');
      $tab[153]['forcegroupby']  = true;
      $tab[153]['massiveaction'] = false;
      $tab[153]['joinparams']    = array('jointype' => 'child');

      $tab[154]['table']         = 'glpi_computerdisks';
      $tab[154]['field']         = 'device';
      $tab[154]['name']          = __('Partition');
      $tab[154]['forcegroupby']  = true;
      $tab[154]['massiveaction'] = false;
      $tab[154]['joinparams']    = array('jointype' => 'child');

      $tab[155]['table']         = 'glpi_filesystems';
      $tab[155]['field']         = 'name';
      $tab[155]['name']          = __('File system');
      $tab[155]['forcegroupby']  = true;
      $tab[155]['massiveaction'] = false;
      $tab[155]['joinparams']    = array('beforejoin'
                                         => array('table'      => 'glpi_computerdisks',
                                                  'joinparams' => array('jointype' => 'child')));

      if ($CFG_GLPI["use_ocs_mode"]) {
         $tab['ocsng']              = __('OCSNG');

         $tab[102]['table']         = 'glpi_ocslinks';
         $tab[102]['field']         = 'last_update';
         $tab[102]['name']          = __('Import date in GLPI');
         $tab[102]['datatype']      = 'datetime';
         $tab[102]['massiveaction'] = false;
         $tab[102]['joinparams']    = array('jointype' => 'child');

         $tab[103]['table']         = 'glpi_ocslinks';
         $tab[103]['field']         = 'last_ocs_update';
         $tab[103]['name']          = __('Last OCSNG inventory date');
         $tab[103]['datatype']      = 'datetime';
         $tab[103]['massiveaction'] = false;
         $tab[103]['joinparams']    = array('jointype' => 'child');

         $tab[100]['table']         = $this->getTable();
         $tab[100]['field']         = 'is_ocs_import';
         $tab[100]['name']          = __('Imported from OCSNG');
         $tab[100]['massiveaction'] = false;
         $tab[100]['datatype']      = 'bool';

         $tab[101]['table']         = 'glpi_ocslinks';
         $tab[101]['field']         = 'use_auto_update';
         $tab[101]['linkfield']     = '_auto_update_ocs'; // update through compter update process
         $tab[101]['name']          = __('Auto update OCSNG');
         $tab[101]['datatype']      = 'bool';
         $tab[101]['joinparams']    = array('jointype' => 'child');

         $tab[104]['table']         = 'glpi_ocslinks';
         $tab[104]['field']         = 'ocs_agent_version';
         $tab[104]['name']          = __('Agent');
         $tab[104]['massiveaction'] = false;
         $tab[104]['joinparams']    = array('jointype' => 'child');

         $tab[105]['table']         = 'glpi_ocslinks';
         $tab[105]['field']         = 'tag';
         $tab[105]['name']          = __('OCSNG TAG');
         $tab[105]['datatype']      = 'string';
         $tab[105]['massiveaction'] = false;
         $tab[105]['joinparams']    = array('jointype' => 'child');

         $tab[106]['table']         = 'glpi_ocslinks';
         $tab[106]['field']         = 'ocsid';
         $tab[106]['name']          = __('OCS ID');
         $tab[106]['datatype']      = 'number';
         $tab[106]['massiveaction'] = false;
         $tab[106]['joinparams']    = array('jointype' => 'child');

         $tab['registry']           = __('Registry');

         $tab[110]['table']         = 'glpi_registrykeys';
         $tab[110]['field']         = 'value';
         $tab[110]['name']          = __('Registry key/value');
         $tab[110]['forcegroupby']  = true;
         $tab[110]['massiveaction'] = false;
         $tab[110]['joinparams']    = array('jointype' => 'child');

         $tab[111]['table']         = 'glpi_registrykeys';
         $tab[111]['field']         = 'ocs_name';
         $tab[111]['name']          = __('Registry OCSNG name');
         $tab[111]['forcegroupby']  = true;
         $tab[111]['massiveaction'] = false;
         $tab[111]['joinparams']    = array('jointype' => 'child');
      }
      $tab['virtualmachine']        = _n('Virtual machine', 'Virtual machines', 2);

      $tab[160]['table']            = 'glpi_computervirtualmachines';
      $tab[160]['field']            = 'name';
      $tab[160]['name']             = __('Virtual machine');
      $tab[160]['forcegroupby']     = true;
      $tab[160]['massiveaction']    = false;
      $tab[160]['joinparams']       = array('jointype' => 'child');

      $tab[161]['table']            = 'glpi_virtualmachinestates';
      $tab[161]['field']            = 'name';
      $tab[161]['name']             = __('State of the virtual machine');
      $tab[161]['forcegroupby']     = true;
      $tab[161]['massiveaction']    = false;
      $tab[161]['joinparams']       = array('beforejoin'
                                             => array('table'      => 'glpi_computervirtualmachines',
                                                      'joinparams' => array('jointype' => 'child')));

      $tab[162]['table']            = 'glpi_virtualmachinetypes';
      $tab[162]['field']            = 'name';
      $tab[162]['name']             = __('Virtualization model');
      $tab[162]['forcegroupby']     = true;
      $tab[162]['massiveaction']    = false;
      $tab[162]['joinparams']       = array('beforejoin'
                                             => array('table'      => 'glpi_computervirtualmachines',
                                                      'joinparams' => array('jointype' => 'child')));

      $tab[163]['table']            = 'glpi_virtualmachinetypes';
      $tab[163]['field']            = 'name';
      $tab[163]['name']             = __('Virtualization system');
      $tab[163]['forcegroupby']     = true;
      $tab[163]['massiveaction']    = false;
      $tab[163]['joinparams']       = array('beforejoin'
                                             => array('table'      => 'glpi_computervirtualmachines',
                                                      'joinparams' => array('jointype' => 'child')));

      $tab[164]['table']            = 'glpi_computervirtualmachines';
      $tab[164]['field']            = 'vcpu';
      $tab[164]['name']             = __('Virtual machine name');
      $tab[164]['forcegroupby']     = true;
      $tab[164]['massiveaction']    = false;
      $tab[164]['joinparams']       = array('jointype' => 'child');

      $tab[165]['table']            = 'glpi_computervirtualmachines';
      $tab[165]['field']            = 'ram';
      $tab[165]['name']             = __('Memory of virtual machines');
      $tab[165]['forcegroupby']     = true;
      $tab[165]['massiveaction']    = false;
      $tab[165]['joinparams']       = array('jointype' => 'child');
      $tab[165]['datatype']         = 'integer';

      $tab[166]['table']            = 'glpi_computervirtualmachines';
      $tab[166]['field']            = 'uuid';
      $tab[166]['name']             = __('Virtual machine UUID');
      $tab[166]['forcegroupby']     = true;
      $tab[166]['massiveaction']    = false;
      $tab[166]['joinparams']       = array('jointype' => 'child');
      $tab[166]['datatype']         = 'integer';

      return $tab;
   }
}
?>

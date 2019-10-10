<?php
/*
 * LibreNMS module to capture statistics from the CISCO-NTP-MIB
 *
 * Copyright (c) 2016 Aaron Daniels <aaron@daniels.id.au>
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the
 * Free Software Foundation, either version 3 of the License, or (at your
 * option) any later version.  Please see LICENSE.txt at the top level of
 * the source code distribution for details.
 */

use LibreNMS\Util\IP;

$module = 'ntp';

$component = new LibreNMS\Component();
$components = $component->getComponents($device['device_id'], array('type'=>$module));

// We only care about our device id.
$components = $components[$device['device_id']];

// Begin our master array, all other values will be processed into this array.
$tblComponents = array();

// Let's gather some data..
// For Reference:
//      http://www.oidview.com/mibs/9/CISCO-NTP-MIB.html
//      http://www.cisco.com/c/en/us/support/docs/availability/high-availability/19643-ntpm.html
$ntpAssocTable = snmpwalk_group($device, 'ntpAssocEntry', SPECTRACOM-NTP-V4-MIB);

/*
 * False == no object found - this is not an error, no objects exist
 * null  == timeout or something else that caused an error, there may be objects but we couldn't get it.
 */
if (is_null($ntpAssocTable)) {
    // We have to error here or we will end up deleting all our components.
    echo "Error\n";
} else {
    // No Error, lets process things.
    d_echo("Objects Found:\n");

    // Let's grab the index for each NTP peer
    foreach ($ntpArrayEntry as $index => $value) {
        $result = array();
        $result['UID'] = (string)$index;    // This is cast as a string so it can be compared with the database value.
        $result['peer'] = $ntpAssocTable[$index]['assocEntryIdentity'];
        $result['port'] = '123'; // sync by GPS so we use presume default port 
        $result['stratum'] = $ntpAssocTable[$index]['assocEntryStratum'];
        $result['peerref'] = $ntpAssocTable[$index]['assocEntryRefId'];
        $result['label'] = $result['peer'].":".$result['port'];

        // Set the status, 16 = Bad
        if ($result['stratum'] == 16) {
            $result['status'] = 2;
            $result['error'] = 'NTP is not in sync';
        } else {
            $result['status'] = 0;
            $result['error'] = '';
        }

        d_echo("NTP Peer found: ");
        d_echo($result);
        $tblComponents[] = $result;
    }

    /*
     * Ok, we have our 2 array's (Components and SNMP) now we need
     * to compare and see what needs to be added/updated.
     *
     * Let's loop over the SNMP data to see if we need to ADD or UPDATE any components.
     */
    foreach ($tblComponents as $key => $array) {
        $component_key = false;

        // Loop over our components to determine if the component exists, or we need to add it.
        foreach ($components as $compid => $child) {
            if ($child['UID'] === $array['UID']) {
                $component_key = $compid;
            }
        }

        if (!$component_key) {
            // The component doesn't exist, we need to ADD it - ADD.
            $new_component = $component->createComponent($device['device_id'], $module);
            $component_key = key($new_component);
            $components[$component_key] = array_merge($new_component[$component_key], $array);
            echo "+";
        } else {
            // The component does exist, merge the details in - UPDATE.
            $components[$component_key] = array_merge($components[$component_key], $array);
            echo ".";
        }
    }

    /*
     * Loop over the Component data to see if we need to DELETE any components.
     */
    foreach ((array)$components as $key => $array) {
        // Guilty until proven innocent
        $found = false;

        foreach ($tblComponents as $k => $v) {
            if ($array['UID'] == $v['UID']) {
                // Yay, we found it...
                $found = true;
            }
        }

        if ($found === false) {
            // The component has not been found. we should delete it.
            echo "-";
            $component->deleteComponent($key);
        }
    }

    // Write the Components back to the DB.
    $component->setComponentPrefs($device['device_id'], $components);
    echo "\n";
} // End if not error

$module = strtolower($module);
if (!empty($components)) {
    if (dbFetchCell('SELECT COUNT(*) FROM `applications` WHERE `device_id` = ? AND `app_type` = ?', array($device['device_id'], $module)) == '0') {
        dbInsert(array('device_id' => $device['device_id'], 'app_type' => $module, 'app_status' => '', 'app_instance' => ''), 'applications');
    }
} else {
    dbDelete('applications', '`device_id` = ? AND `app_type` = ?', array($device['device_id'], $module));
}

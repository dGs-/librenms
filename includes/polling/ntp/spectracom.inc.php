<?php
/*
 * LibreNMS module to capture statistics from the AT-NTP-MIB
 *
 * Copyright (c) 2018 Matt Read <matt.read@alliedtelesis.co.nz>
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the
 * Free Software Foundation, either version 3 of the License, or (at your
 * option) any later version.  Please see LICENSE.txt at the top level of
 * the source code distribution for details.
 */

use LibreNMS\RRD\RrdDefinition;

$tmp_module = 'ntp';

$component = new LibreNMS\Component();
$options = array();
$options['filter']['type'] = array('=',$tmp_module);
$options['filter']['disabled'] = array('=',0);
$options['filter']['ignore'] = array('=',0);
$components = $component->getComponents($device['device_id'], $options);

// We only care about our device id.
$components = $components[$device['device_id']];

// Only collect SNMP data if we have enabled components
if (count($components > 0)) {
    // Let's gather the stats..
    $ntpAssocEntry = snmpwalk_group($device, 'ntpAssocEntry', 'SPECTRACOM-NTP-V4-MIB');

    // Loop through the components and extract the data.
    foreach ($components as $key => &$array) {
        $peer = $array['peer'];

        // Let's make sure the rrd is setup for this class.
        $rrd_name = array('ntp', $peer);
        $rrd_def = RrdDefinition::make()
            ->addDataset('stratum', 'GAUGE', 0, 16)
            ->addDataset('offset', 'GAUGE', -1000)
            ->addDataset('delay', 'GAUGE', -1000)
            ->addDataset('dispersion', 'GAUGE', -1000);

        $array['stratum'] = $ntpAssocEntry[$array['UID']]['assocEntryStratum'];
        // Set the status, 16 = Bad
        if ($array['stratum'] == 16) {
            $array['status'] = 2;
            $array['error'] = 'NTP is not in sync';
        } else {
            $array['status'] = 0;
            $array['error'] = '';
        }

        // Extract the statistics and update rrd
        $rrd['stratum'] = $array['stratum'];
        $rrd['offset'] = $ntpAssocEntry[$array['UID']]['assocEntryOffset'];
        $rrd['offset'] = str_replace(' milliseconds', '', $rrd['offset']);
        $rrd['offset'] = $rrd['offset'] / 1000; // Convert to seconds
        $rrd['delay'] = $ntpAssocEntry[$array['UID']]['assocEntryDelay'];
        $rrd['delay'] =  str_replace(' milliseconds', '', $rrd['delay']);
        $rrd['delay'] = $rrd['delay'] / 1000; // Convert to seconds
        $rrd['dispersion'] = $ntpAssocEntry[$array['UID']]['assocEntryJitter'];
        $tags = compact('ntp', 'rrd_name', 'rrd_def', 'peer');
        data_update($device, 'ntp', $tags, $rrd);

        // Let's print some debugging info.
        d_echo("\n\nComponent: ".$key."\n");
        d_echo("    Index:      ".$array['UID']."\n");
        d_echo("    Peer:       ".$array['peer'].":".$array['port']."\n");
        d_echo("    Stratum:    assocEntryStratum.".$array['UID']."  = ".$rrd['stratum']."\n");
        d_echo("    Offset:     assocEntryOffset.".$array['UID']." = ".$rrd['offset']."\n");
        d_echo("    Delay:      assocEntryDelay.".$array['UID']." = ".$rrd['delay']."\n");
        d_echo("    Dispersion: assocEntryJitter.".$array['UID']." = ".$rrd['dispersion']."\n");

        // Clean-up after yourself!
        unset($filename, $rrd_filename, $rrd);
    } // End foreach components

    // Write the Components back to the DB.
    $component->setComponentPrefs($device['device_id'], $components);
} // end if count components

// Clean-up after yourself!
unset($type, $components, $component, $options, $tmp_module);

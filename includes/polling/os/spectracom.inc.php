<?php
$version  = snmp_get($device, 'ssSysStaVersion.0', '-Ovq', 'SPECTRACOM-SECURESYNC-MIB');
$serial  = snmp_get($device, 'ssSysStaSerial.0', '-Ovq', 'SPECTRACOM-SECURESYNC-MIB');

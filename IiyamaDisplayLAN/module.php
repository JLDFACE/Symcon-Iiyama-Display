<?php
/*
 * Iiyama Display (LAN/RS232)
 *
 * Designentscheidungen (kurz):
 * - SymBox-sicher: kein strict_types, keine PHP 8 Signaturen/Typen, keine globalen Funktionen.
 * - Device-only: kurzlebige TCP-Verbindung pro Request (stabil bei Request/Response Protokollen).
 * - UX stabil: Pending/Sollwert-Logik verhindert "Flippen" durch Polling.
 * - Performance: Slow/Fast Polling + FastAfterChange.
 * - Robustheit: Online/LastError, Semaphore-Lock ohne Fatal.
 */

class IIYAMA_IiyamaDisplayLAN extends IPSModule
{
    // Variable Idents
    private $IdentPower = 'Power';
    private $IdentInput = 'Input';
    private $IdentVolume = 'Volume';
    private $IdentOperatingHours = 'OperatingHours';
    private $IdentModelName = 'ModelName';
    private $IdentFirmware = 'FirmwareVersion';
    private $IdentOnline = 'Online';
    private $IdentLastError = 'LastError';

    // Buffer keys
    private $BufPendingPower = 'PendingPower';
    private $BufPendingInput = 'PendingInput';
    private $BufPendingVolume = 'PendingVolume';
    private $BufPendingUntilPower = 'PendingUntilPower';
    private $BufPendingUntilInput = 'PendingUntilInput';
    private $BufPendingUntilVolume = 'PendingUntilVolume';
    private $BufFastUntil = 'FastUntil';
    private $BufPowerOnAt = 'PowerOnAt';
    private $BufInputDelayUntil = 'InputDelayUntil';

    public function Create()
    {
        parent::Create();

        // Connection / Protocol
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 5000);
        $this->RegisterPropertyInteger('MonitorID', 1);
        $this->RegisterPropertyInteger('Timeout', 1000);

        // Polling / UX
        $this->RegisterPropertyInteger('PollSlow', 15);
        $this->RegisterPropertyInteger('PollFast', 2);
        $this->RegisterPropertyInteger('FastAfterChange', 30);
        $this->RegisterPropertyInteger('InputDelayAfterPowerOn', 8000);

        $this->RegisterTimer('PollTimer', 0, 'IIYAMA_Poll($_IPS[\'TARGET\']);');

        // Buffers init
        $this->SetBuffer($this->BufPendingPower, '');
        $this->SetBuffer($this->BufPendingInput, '');
        $this->SetBuffer($this->BufPendingVolume, '');
        $this->SetBuffer($this->BufPendingUntilPower, '0');
        $this->SetBuffer($this->BufPendingUntilInput, '0');
        $this->SetBuffer($this->BufPendingUntilVolume, '0');
        $this->SetBuffer($this->BufFastUntil, '0');
        $this->SetBuffer($this->BufPowerOnAt, '0');
        $this->SetBuffer($this->BufInputDelayUntil, '0');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterProfiles();
        $this->RegisterVariables();

        $this->UpdateForm();

        // Start polling if host set
        $this->UpdatePollTimer();
        $this->Poll();
    }

    public function GetConfigurationForm()
    {
        return file_get_contents(__DIR__ . '/form.json');
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident == $this->IdentPower) {
            $this->SetPower($Value ? 1 : 0);
            return;
        }
        if ($Ident == $this->IdentInput) {
            $this->SetInput((int)$Value);
            return;
        }
        if ($Ident == $this->IdentVolume) {
            $this->SetVolume((int)$Value);
            return;
        }

        IPS_LogMessage(__CLASS__, 'Unknown Ident in RequestAction: ' . $Ident);
    }

    public function Poll()
    {
        // Non-fatal lock
        if (!$this->Lock('Poll', 2000)) {
            // Try again soon
            $this->EnterFastPoll(10);
            return;
        }

        try {
            $host = trim($this->ReadPropertyString('Host'));
            if ($host == '') {
                $this->SetOnline(false, 'Host not configured');
                $this->Unlock('Poll');
                return;
            }

            // Get power first (helps to decide InputDelay logic)
            $power = $this->GetPower();
            if ($power === false) {
                $this->SetOnline(false, 'No response (Power)');
                $this->Unlock('Poll');
                return;
            }

            $prevPower = $this->GetValueSafe($this->IdentPower);
            $this->UpdatePowerVarFromPoll($power);

            // Detect Off->On transition
            if ($prevPower === false) {
                // first run -> ignore transition logic
            } else {
                if ((int)$prevPower == 0 && (int)$power == 1) {
                    $this->SetBuffer($this->BufPowerOnAt, (string)$this->Now());
                    $delayMs = (int)$this->ReadPropertyInteger('InputDelayAfterPowerOn');
                    if ($delayMs < 0) {
                        $delayMs = 0;
                    }
                    $this->SetBuffer($this->BufInputDelayUntil, (string)($this->Now() + (int)($delayMs / 1000)));
                }
            }

            // Input
            $input = $this->GetInput();
            if ($input !== false) {
                $this->UpdateInputVarFromPoll($input);
            }

            // Volume
            $vol = $this->GetVolume();
            if ($vol !== false) {
                $this->UpdateVolumeVarFromPoll($vol);
            }

            // Operating hours
            $hours = $this->GetOperatingHours();
            if ($hours !== false) {
                $this->SetValueIfChanged($this->IdentOperatingHours, (int)$hours);
            }

            // Labels (Model + FW) - less frequent; still OK in 15s, but we can avoid chatter:
            // Poll them every 10 minutes, or on startup.
            $lastInfo = (int)$this->GetBuffer('LastInfoPoll');
            if ($lastInfo <= 0 || ($this->Now() - $lastInfo) >= 600) {
                $this->SetBuffer('LastInfoPoll', (string)$this->Now());

                $model = $this->GetLabel(1);
                if ($model !== false) {
                    $this->SetValueIfChanged($this->IdentModelName, $model);
                }

                $fw = $this->GetLabel(0);
                if ($fw !== false) {
                    $this->SetValueIfChanged($this->IdentFirmware, $fw);
                }
            }

            $this->SetOnline(true, '');

            // FastAfterChange if any pending exists or recent change was detected
            $this->UpdateFastPollByPending();

        } catch (Exception $e) {
            $this->SetOnline(false, 'Exception: ' . $e->getMessage());
        }

        $this->Unlock('Poll');
        $this->UpdatePollTimer();
    }

    public function UpdateNow()
    {
        $this->Poll();
    }

    // -------------------------
    // Actions (Set)
    // -------------------------

    private function SetPower($on)
    {
        if (!$this->Lock('Action', 2000)) {
            $this->EnterFastPoll(10);
            return false;
        }

        $target = ((int)$on) ? 1 : 0;

        // Pending
        $this->SetPending($this->BufPendingPower, $this->BufPendingUntilPower, (string)$target, 20);
        $this->SetValueIfChanged($this->IdentPower, $target);

        // Protocol: Power Set uses cmd 0x18; data1: 0x01=Off, 0x02=On
        $data1 = ($target == 1) ? 0x02 : 0x01;
        $ok = $this->SendSetCommand(0x18, array($data1));

        if ($ok) {
            $this->EnterFastPoll($this->ReadPropertyInteger('FastAfterChange'));
        } else {
            // keep pending; poll will reconcile / timeout
            $this->EnterFastPoll(20);
        }

        $this->Unlock('Action');
        $this->UpdatePollTimer();
        return $ok;
    }

    private function SetVolume($value)
    {
        if (!$this->Lock('Action', 2000)) {
            $this->EnterFastPoll(10);
            return false;
        }

        $v = (int)$value;
        if ($v < 0) $v = 0;
        if ($v > 100) $v = 100;

        $this->SetPending($this->BufPendingVolume, $this->BufPendingUntilVolume, (string)$v, 15);
        $this->SetValueIfChanged($this->IdentVolume, $v);

        // Volume Set cmd 0x44; range 0..100 (device dependent)
        $ok = $this->SendSetCommand(0x44, array($v));

        if ($ok) {
            $this->EnterFastPoll($this->ReadPropertyInteger('FastAfterChange'));
        } else {
            $this->EnterFastPoll(20);
        }

        $this->Unlock('Action');
        $this->UpdatePollTimer();
        return $ok;
    }

    private function SetInput($enumValue)
    {
        if (!$this->Lock('Action', 2000)) {
            $this->EnterFastPoll(10);
            return false;
        }

        $target = (int)$enumValue;

        // If display is off, store pending and try to power on? (Nicht automatisch, um Nebenwirkungen zu vermeiden.)
        // Wir setzen Pending; wenn später Power On erfolgt, wird Input nach Delay gesetzt.
        $this->SetPending($this->BufPendingInput, $this->BufPendingUntilInput, (string)$target, 40);
        $this->SetValueIfChanged($this->IdentInput, $target);

        $power = $this->GetValueSafe($this->IdentPower);
        if ($power === false || (int)$power == 0) {
            // Display off -> don't send immediately, just keep pending
            $this->EnterFastPoll(20);
            $this->Unlock('Action');
            $this->UpdatePollTimer();
            return true;
        }

        // If in input-delay window (only after real power on), postpone
        if ($this->IsInInputDelayWindow()) {
            $this->EnterFastPoll(20);
            $this->Unlock('Action');
            $this->UpdatePollTimer();
            return true;
        }

        $ok = $this->SendInputSetByEnum($target);

        if ($ok) {
            $this->EnterFastPoll($this->ReadPropertyInteger('FastAfterChange'));
        } else {
            $this->EnterFastPoll(20);
        }

        $this->Unlock('Action');
        $this->UpdatePollTimer();
        return $ok;
    }

    // -------------------------
    // Get (Poll)
    // -------------------------

    private function GetPower()
    {
        // Power Get cmd 0x19
        $rep = $this->SendGetCommand(0x19, array());
        if ($rep === false) return false;

        // Expect data[0]=0x19, data[1]=state
        if (!isset($rep['data'][1])) return false;
        $state = (int)$rep['data'][1]; // 0x01 off, 0x02 on
        if ($state == 0x02) return 1;
        if ($state == 0x01) return 0;
        return false;
    }

    private function GetVolume()
    {
        // Volume Get cmd 0x45
        $rep = $this->SendGetCommand(0x45, array());
        if ($rep === false) return false;
        if (!isset($rep['data'][1])) return false;
        return (int)$rep['data'][1];
    }

    private function GetInput()
{
    // Current Source Get cmd 0xAD
    // Newer iiyama RS232/LAN specs report the input source directly as a type code in DATA[1]
    // (DATA[2..] are reserved), e.g. 0x0D=HDMI1, 0x06=HDMI2, 0x10=Browser, ...
    $rep = $this->SendGetCommand(0xAD, array());
    if ($rep === false) return false;

    if (!isset($rep['data'][1])) return false;

    $typeCode = (int)$rep['data'][1];
    $enum = $this->MapTypeCodeToEnum($typeCode);
    if ($enum < 0) {
        return false;
    }
    return $enum;
}

    private function GetOperatingHours()
    {
        // Misc Info Get (0x0F), subcommand 0x02 = Operating Hours
        $rep = $this->SendGetCommand(0x0F, array(0x02));
        if ($rep === false) return false;
        if (!isset($rep['data'][1]) || !isset($rep['data'][2])) return false;

        $msb = (int)$rep['data'][1];
        $lsb = (int)$rep['data'][2];
        return ($msb << 8) + $lsb;
    }

    private function GetLabel($which)
    {
        // Platform and Version Labels: cmd 0xA2, data1 0x00 = FW, 0x01 = model platform
        $w = (int)$which;
        if ($w != 0 && $w != 1) $w = 0;

        $rep = $this->SendGetCommand(0xA2, array($w));
        if ($rep === false) return false;

        // data[0]=0xA2, data[1..N]=ASCII
        $bytes = array();
        $i = 1;
        while (isset($rep['data'][$i])) {
            $bytes[] = chr((int)$rep['data'][$i]);
            $i++;
        }
        $s = trim(implode('', $bytes));
        return $s;
    }

    // -------------------------
    // Protocol helpers
    // -------------------------

    private function SendInputSetByEnum($enumValue)
{
    $map = $this->GetInputEnumMap();
    if (!isset($map[$enumValue])) {
        $this->SetLastError('Unknown input enum: ' . $enumValue);
        return false;
    }

    $typeCode = (int)$map[$enumValue]['typeCode'];

    // Input Source Set cmd 0xAC
    // DATA[1]=Input Source Type Code, DATA[2..4]=reserved (0)
    $data = array(
        $typeCode,
        0x00,
        0x00,
        0x00
    );

    return $this->SendSetCommand(0xAC, $data);
}

    private function SendGetCommand($cmd, $dataTail)
    {
        $packet = $this->BuildPacket(0xA6, (int)$cmd, $dataTail);
        $resp = $this->SendAndReceive($packet);
        if ($resp === false) return false;

        // For Get, display returns a report packet with header 0x21
        if ($resp['header'] != 0x21) return false;

        // If response is ACK/NACK/NAV instead of report, treat as error.
        // For reports, data[0] should echo cmd; for some devices it may still reply with cmd in data[0].
        if (!isset($resp['data'][0])) return false;

        if ((int)$resp['data'][0] != (int)$cmd) {
            // Some firmwares could return 0x00 patterns; still accept if looks like a report (len>2) for label requests.
            // If it's an ACK/NACK style, data[1] indicates status.
            if (isset($resp['data'][1]) && ((int)$resp['data'][1] == 0x00 || (int)$resp['data'][1] == 0x03 || (int)$resp['data'][1] == 0x04)) {
                $this->SetLastError('Unexpected ACK/NACK/NAV for GET cmd 0x' . strtoupper(dechex($cmd)));
                return false;
            }
        }

        return $resp;
    }

    private function SendSetCommand($cmd, $dataTail)
    {
        $packet = $this->BuildPacket(0xA6, (int)$cmd, $dataTail);
        $resp = $this->SendAndReceive($packet);
        if ($resp === false) return false;

        // For Set, display returns ACK/NACK/NAV report (header 0x21)
        if ($resp['header'] != 0x21) return false;

        // ACK: data[1] == 0x00; NACK: 0x03; NAV: 0x04
        if (!isset($resp['data'][1])) return false;

        $code = (int)$resp['data'][1];
        if ($code == 0x00) {
            return true;
        }
        if ($code == 0x03) {
            $this->SetLastError('NACK for SET cmd 0x' . strtoupper(dechex($cmd)));
            return false;
        }
        if ($code == 0x04) {
            $this->SetLastError('NAV for SET cmd 0x' . strtoupper(dechex($cmd)));
            return false;
        }

        $this->SetLastError('Unexpected response code for SET cmd 0x' . strtoupper(dechex($cmd)) . ': 0x' . strtoupper(dechex($code)));
        return false;
    }

    private function BuildPacket($header, $cmd, $dataTail)
    {
        $id = (int)$this->ReadPropertyInteger('MonitorID');
        if ($id < 1) $id = 1;
        if ($id > 255) $id = 255;

        // Packet: A6 ID 00 00 00 LEN 01 DATA... CHK
        $data = array();
        $data[] = (int)$cmd;

        if (is_array($dataTail)) {
            foreach ($dataTail as $b) {
                $data[] = (int)$b;
            }
        }

        $len = count($data) + 3; // N + 3 per spec

        $bytes = array(
            (int)$header,
            $id,
            0x00, // category fixed
            0x00, // code0/page fixed
            0x00, // code1/function fixed
            $len,
            0x01  // data control fixed
        );

        foreach ($data as $b) {
            $bytes[] = $b;
        }

        $chk = 0x00;
        foreach ($bytes as $b) {
            $chk = $chk ^ ($b & 0xFF);
        }
        $bytes[] = $chk & 0xFF;

        // Convert to binary string
        $out = '';
        foreach ($bytes as $b) {
            $out .= chr($b & 0xFF);
        }
        return $out;
    }

    private function SendAndReceive($binaryPacket)
    {
        $host = trim($this->ReadPropertyString('Host'));
        $port = (int)$this->ReadPropertyInteger('Port');
        $timeoutMs = (int)$this->ReadPropertyInteger('Timeout');
        if ($timeoutMs < 250) $timeoutMs = 250;

        $errno = 0;
        $errstr = '';

        $timeoutSec = $timeoutMs / 1000.0;

        $fp = @fsockopen($host, $port, $errno, $errstr, $timeoutSec);
        if (!$fp) {
            $this->SetLastError('Connect failed: ' . $errstr . ' (' . $errno . ')');
            return false;
        }

        stream_set_timeout($fp, (int)$timeoutSec, (int)(($timeoutSec - (int)$timeoutSec) * 1000000));

        $len = strlen($binaryPacket);
        $written = 0;
        while ($written < $len) {
            $w = @fwrite($fp, substr($binaryPacket, $written));
            if ($w === false || $w === 0) {
                @fclose($fp);
                $this->SetLastError('Write failed');
                return false;
            }
            $written += $w;
        }

        // Read first 5 bytes (header,id,cat,page,msglen)
        $hdr = $this->ReadBytes($fp, 5);
        if ($hdr === false || strlen($hdr) < 5) {
            @fclose($fp);
            $this->SetLastError('Read header failed');
            return false;
        }

        $h = ord($hdr[0]);
        $msglen = ord($hdr[4]); // Control..Checksum length

        $rest = $this->ReadBytes($fp, $msglen);
        @fclose($fp);

        if ($rest === false || strlen($rest) < $msglen) {
            $this->SetLastError('Read payload failed');
            return false;
        }

        $packet = $hdr . $rest;

        // Parse and checksum verify
        $parsed = $this->ParseResponse($packet);
        if ($parsed === false) {
            $this->SetLastError('Invalid response / checksum');
            return false;
        }

        return $parsed;
    }

    private function ReadBytes($fp, $len)
    {
        $data = '';
        $remaining = (int)$len;

        while ($remaining > 0) {
            $chunk = @fread($fp, $remaining);
            if ($chunk === false) {
                return false;
            }
            if ($chunk === '') {
                $meta = stream_get_meta_data($fp);
                if (isset($meta['timed_out']) && $meta['timed_out']) {
                    return false;
                }
                break;
            }
            $data .= $chunk;
            $remaining = $len - strlen($data);
        }

        return $data;
    }

    private function ParseResponse($binary)
    {
        if (strlen($binary) < 7) return false;

        $header = ord($binary[0]);
        $id = ord($binary[1]);
        $category = ord($binary[2]);
        $page = ord($binary[3]);
        $msglen = ord($binary[4]);

        $totalLen = 5 + $msglen;
        if (strlen($binary) != $totalLen) {
            return false;
        }

        $chk = 0x00;
        for ($i = 0; $i < $totalLen - 1; $i++) {
            $chk = $chk ^ ord($binary[$i]);
        }
        $chk = $chk & 0xFF;

        $recvChk = ord($binary[$totalLen - 1]) & 0xFF;
        if ($chk != $recvChk) {
            return false;
        }

        $control = ord($binary[5]);
        $dataBytes = array();
        $dataLen = $msglen - 2; // control + checksum
        for ($i = 0; $i < $dataLen; $i++) {
            $dataBytes[$i] = ord($binary[6 + $i]) & 0xFF;
        }

        return array(
            'header'   => $header,
            'id'       => $id,
            'category' => $category,
            'page'     => $page,
            'msglen'   => $msglen,
            'control'  => $control,
            'data'     => $dataBytes
        );
    }

    // -------------------------
    // UX / Pending / Polling
    // -------------------------

    private function UpdatePowerVarFromPoll($powerOn)
    {
        $pending = $this->GetBuffer($this->BufPendingPower);
        $pendingUntil = (int)$this->GetBuffer($this->BufPendingUntilPower);

        if ($pending !== '' && $this->Now() <= $pendingUntil) {
            // Don't flip UI. Clear pending once reached.
            if ((int)$pending == (int)$powerOn) {
                $this->ClearPending($this->BufPendingPower, $this->BufPendingUntilPower);
            }
            return;
        }

        // Pending expired or none -> update from actual
        $this->ClearPending($this->BufPendingPower, $this->BufPendingUntilPower);
        $this->SetValueIfChanged($this->IdentPower, (int)$powerOn);
    }

    private function UpdateInputVarFromPoll($inputEnum)
    {
        $pending = $this->GetBuffer($this->BufPendingInput);
        $pendingUntil = (int)$this->GetBuffer($this->BufPendingUntilInput);

        if ($pending !== '' && $this->Now() <= $pendingUntil) {
            if ((int)$pending == (int)$inputEnum) {
                $this->ClearPending($this->BufPendingInput, $this->BufPendingUntilInput);
            }
            return;
        }

        $this->ClearPending($this->BufPendingInput, $this->BufPendingUntilInput);
        $this->SetValueIfChanged($this->IdentInput, (int)$inputEnum);
    }

    private function UpdateVolumeVarFromPoll($vol)
    {
        $pending = $this->GetBuffer($this->BufPendingVolume);
        $pendingUntil = (int)$this->GetBuffer($this->BufPendingUntilVolume);

        if ($pending !== '' && $this->Now() <= $pendingUntil) {
            if ((int)$pending == (int)$vol) {
                $this->ClearPending($this->BufPendingVolume, $this->BufPendingUntilVolume);
            }
            return;
        }

        $this->ClearPending($this->BufPendingVolume, $this->BufPendingUntilVolume);
        $this->SetValueIfChanged($this->IdentVolume, (int)$vol);
    }

    private function UpdateFastPollByPending()
    {
        $now = $this->Now();

        $p1 = $this->GetBuffer($this->BufPendingPower);
        $p2 = $this->GetBuffer($this->BufPendingInput);
        $p3 = $this->GetBuffer($this->BufPendingVolume);

        $u1 = (int)$this->GetBuffer($this->BufPendingUntilPower);
        $u2 = (int)$this->GetBuffer($this->BufPendingUntilInput);
        $u3 = (int)$this->GetBuffer($this->BufPendingUntilVolume);

        $hasActivePending = false;
        if ($p1 !== '' && $now <= $u1) $hasActivePending = true;
        if ($p2 !== '' && $now <= $u2) $hasActivePending = true;
        if ($p3 !== '' && $now <= $u3) $hasActivePending = true;

        // Also keep fast if within InputDelay window
        if ($this->IsInInputDelayWindow()) {
            $hasActivePending = true;
        }

        // If pending input exists and delay window ended, try to apply it
        $this->TryApplyPendingInputAfterDelay();

        if ($hasActivePending) {
            $this->EnterFastPoll(20);
        }
    }

    private function TryApplyPendingInputAfterDelay()
    {
        if ($this->IsInInputDelayWindow()) return;

        $pending = $this->GetBuffer($this->BufPendingInput);
        $pendingUntil = (int)$this->GetBuffer($this->BufPendingUntilInput);
        if ($pending === '' || $this->Now() > $pendingUntil) return;

        $power = $this->GetValueSafe($this->IdentPower);
        if ($power === false || (int)$power == 0) return;

        // Attempt to set now
        $this->SendInputSetByEnum((int)$pending);
    }

    private function IsInInputDelayWindow()
    {
        $until = (int)$this->GetBuffer($this->BufInputDelayUntil);
        if ($until <= 0) return false;
        return $this->Now() < $until;
    }

    private function EnterFastPoll($seconds)
    {
        $s = (int)$seconds;
        if ($s <= 0) return;
        $until = $this->Now() + $s;

        $cur = (int)$this->GetBuffer($this->BufFastUntil);
        if ($until > $cur) {
            $this->SetBuffer($this->BufFastUntil, (string)$until);
        }
    }

    private function UpdatePollTimer()
    {
        $host = trim($this->ReadPropertyString('Host'));
        if ($host == '') {
            $this->SetTimerInterval('PollTimer', 0);
            return;
        }

        $slow = (int)$this->ReadPropertyInteger('PollSlow');
        $fast = (int)$this->ReadPropertyInteger('PollFast');
        if ($slow < 5) $slow = 5;
        if ($fast < 2) $fast = 2;

        $now = $this->Now();
        $fastUntil = (int)$this->GetBuffer($this->BufFastUntil);

        $interval = $slow;
        if ($fastUntil > $now) {
            $interval = $fast;
        }

        $this->SetTimerInterval('PollTimer', $interval * 1000);
    }

    private function SetPending($bufKey, $bufUntilKey, $value, $ttlSeconds)
    {
        $ttl = (int)$ttlSeconds;
        if ($ttl < 5) $ttl = 5;
        $this->SetBuffer($bufKey, (string)$value);
        $this->SetBuffer($bufUntilKey, (string)($this->Now() + $ttl));
    }

    private function ClearPending($bufKey, $bufUntilKey)
    {
        $this->SetBuffer($bufKey, '');
        $this->SetBuffer($bufUntilKey, '0');
    }

    private function Now()
    {
        return time();
    }

    // -------------------------
    // Variables / Profiles
    // -------------------------

    private function RegisterProfiles()
{
    // Input enum profile (LE9864UHS-B1AG)
    $profile = 'IIYAMA.Input';
    if (!IPS_VariableProfileExists($profile)) {
        IPS_CreateVariableProfile($profile, 1);
    }

    // Reset associations to avoid duplicates after updates
    $p = IPS_GetVariableProfile($profile);
    if (isset($p['Associations']) && is_array($p['Associations'])) {
        foreach ($p['Associations'] as $a) {
            if (isset($a['Value'])) {
                @IPS_SetVariableProfileAssociation($profile, $a['Value'], '', '', -1);
            }
        }
    }

    IPS_SetVariableProfileAssociation($profile, 0, 'HDMI1', '', -1);
    IPS_SetVariableProfileAssociation($profile, 1, 'HDMI2', '', -1);
    IPS_SetVariableProfileAssociation($profile, 2, 'USB-C', '', -1);
    IPS_SetVariableProfileAssociation($profile, 3, 'Browser', '', -1);
    IPS_SetVariableProfileAssociation($profile, 4, 'CMS', '', -1);
    IPS_SetVariableProfileAssociation($profile, 5, 'File Manager', '', -1);
    IPS_SetVariableProfileAssociation($profile, 6, 'Media Player', '', -1);
    IPS_SetVariableProfileAssociation($profile, 7, 'PDF Player', '', -1);
    IPS_SetVariableProfileAssociation($profile, 8, 'Custom', '', -1);

    $vprof = 'IIYAMA.Volume';
    if (!IPS_VariableProfileExists($vprof)) {
        IPS_CreateVariableProfile($vprof, 1);
    }
    IPS_SetVariableProfileValues($vprof, 0, 100, 1);
}

    private function RegisterVariables()
    {
        $this->RegisterVariableBoolean($this->IdentPower, 'Power', '~Switch', 10);
        $this->EnableAction($this->IdentPower);

        $this->RegisterVariableInteger($this->IdentInput, 'Input', 'IIYAMA.Input', 20);
        $this->EnableAction($this->IdentInput);

        $this->RegisterVariableInteger($this->IdentVolume, 'Volume', 'IIYAMA.Volume', 30);
        $this->EnableAction($this->IdentVolume);

        $this->RegisterVariableInteger($this->IdentOperatingHours, 'Operating Hours', '', 40);

        $this->RegisterVariableString($this->IdentModelName, 'Model Name', '', 50);
        $this->RegisterVariableString($this->IdentFirmware, 'Firmware Version', '', 60);

        $this->RegisterVariableBoolean($this->IdentOnline, 'Online', '~Online', 100);
        $this->RegisterVariableString($this->IdentLastError, 'LastError', '', 110);
    }

    // -------------------------
    // Input mapping
    // -------------------------

    private function GetInputEnumMap()
{
    // LE9864UHS-B1AG: Physical inputs (per spec) are HDMI x2 and USB-C (DP-Alt),
    // plus Android/internal sources (Browser/CMS/File Manager/Media/PDF/Custom).
    //
    // Mapping uses iiyama RS232/LAN "Input Source Type" codes.
    // Common codes (improved 2023 spec): HDMI1=0x0D, HDMI2=0x06, DP1=0x0A, Browser=0x10, CMS=0x11,
    // Internal Storage=0x13, Media Player=0x16, PDF Player=0x17, Custom=0x18.
    return array(
        0 => array('typeCode' => 0x0D), // HDMI1
        1 => array('typeCode' => 0x06), // HDMI2
        2 => array('typeCode' => 0x0A), // USB-C (DP Alt / DP1)
        3 => array('typeCode' => 0x10), // Browser
        4 => array('typeCode' => 0x11), // CMS (SmartCMS / iiSignage)
        5 => array('typeCode' => 0x13), // File Manager (Internal Storage)
        6 => array('typeCode' => 0x16), // Media Player
        7 => array('typeCode' => 0x17), // PDF Player
        8 => array('typeCode' => 0x18)  // Custom
    );
}

    private function MapTypeCodeToEnum($typeCode)
{
    // Map iiyama "Input Source Type" codes to our enum profile (IIYAMA.Input)
    $t = (int)$typeCode;

    if ($t == 0x0D) return 0; // HDMI1
    if ($t == 0x06) return 1; // HDMI2
    if ($t == 0x0A) return 2; // USB-C (DP Alt / DP1)

    if ($t == 0x10) return 3; // Browser
    if ($t == 0x11) return 4; // CMS
    if ($t == 0x13) return 5; // File Manager (Internal Storage)
    if ($t == 0x16) return 6; // Media Player
    if ($t == 0x17) return 7; // PDF Player
    if ($t == 0x18) return 8; // Custom

    return -1;
}

    // -------------------------
    // Diagnostics helpers
    // -------------------------

    private function SetOnline($state, $err)
    {
        $this->SetValueIfChanged($this->IdentOnline, $state ? true : false);
        if ($state) {
            $this->SetStatus(102);
        } else {
            $this->SetStatus(104);
        }
        if ($err != '') {
            $this->SetLastError($err);
        } else {
            // Clear only if previously had an error
            $this->SetLastError('');
        }
    }

    private function SetLastError($msg)
    {
        $this->SetValueIfChanged($this->IdentLastError, (string)$msg);
    }

    private function SetValueIfChanged($ident, $value)
    {
        $vid = @$this->GetIDForIdent($ident);
        if ($vid <= 0) return;

        $cur = GetValue($vid);
        if ($cur === $value) return;

        SetValue($vid, $value);
    }

    private function GetValueSafe($ident)
    {
        $vid = @$this->GetIDForIdent($ident);
        if ($vid <= 0) return false;
        return GetValue($vid);
    }

    // -------------------------
    // Concurrency (Semaphore)
    // -------------------------

    private function Lock($name, $timeoutMs)
    {
        $key = __CLASS__ . '.' . $this->InstanceID . '.' . $name;
        $t = (int)$timeoutMs;
        if ($t < 100) $t = 100;
        $ok = @IPS_SemaphoreEnter($key, $t);
        return $ok ? true : false;
    }

    private function Unlock($name)
    {
        $key = __CLASS__ . '.' . $this->InstanceID . '.' . $name;
        @IPS_SemaphoreLeave($key);
    }

    // -------------------------
    // Form helpers
    // -------------------------

    private function UpdateForm()
    {
        // nothing dynamic for now; keep hook for later
    }
}

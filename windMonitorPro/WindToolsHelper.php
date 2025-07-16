<?php

class WindToolsHelper
{

    // 🔧 Konfigurationseinstellungen
    public static float $gelaendeAlpha = 0.14;
    public static float $referenzhoehe = 80.0;
    public static float $zielHoeheStandard = 10.0;

    public static function setKonfiguration(float $alpha, float $ref, float $ziel, string $typ = "logarithmisch"): void {
        self::$gelaendeAlpha     = $alpha;
        self::$referenzhoehe     = $ref;
        self::$zielHoeheStandard = $ziel;
    }

    //Zuweisung der Zeitzone anhand ueblicherweise verwendeten Kuerzeln wie auch MeteoBlue diese verwendet
    public static function getTimezoneMap(): array {
        return [
            "UTC"  => "UTC",
            "CET"  => "Europe/Berlin",
            "CEST" => "Europe/Berlin",
            "EST"  => "America/New_York",
            "EDT"  => "America/New_York",
            "PST"  => "America/Los_Angeles",
            "PDT"  => "America/Los_Angeles",
            "MST"  => "America/Denver",
            "MDT"  => "America/Denver",
            "GMT"  => "Europe/London",
            "BST"  => "Europe/London",
            "EET"  => "Europe/Helsinki",
            "EEST" => "Europe/Helsinki",
            "IST"  => "Asia/Kolkata",
            "JST"  => "Asia/Tokyo",
            "KST"  => "Asia/Seoul",
            "CST"  => "Asia/Shanghai",
            "AEST" => "Australia/Sydney",
            "AEDT" => "Australia/Sydney",
            "NZST" => "Pacific/Auckland",
            "NZDT" => "Pacific/Auckland"
        ];
    }

    public static function formatToEuropeanDate(string $usDatum): string
    {
        // Versuche, mit Sekunden zu parsen
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $usDatum);

        // Falls das nicht klappt, versuche ohne Sekunden
        if ($dt === false) {
            $dt = DateTime::createFromFormat('Y-m-d H:i', $usDatum);
        }

        // Wenn das Parsen erfolgreich war, gib das europaeische Format zurück
        if ($dt !== false) {
            return $dt->format('d.m.Y H:i:s');
        } else {
            // Fallback: Gib den Originalstring zurück oder einen Fehlertext
            return 'Ungültiges Datum: ' . $usDatum;
        }
    }

    public static function windXmToYm(float $vRef, float $zZiel, float $zRef = 80.0, float $GelaendeAlpha = 0.14): float {
        return $vRef * pow($zZiel / $zRef, $GelaendeAlpha);
    }

    public static function getLokaleModelzeit(array $data, DateTimeZone $targetZone): string {
    //public static function getLokaleModelzeit(array $data): string {
        $rawUTC = $data["metadata"]["modelrun_updatetime_utc"] ?? "";
        if ($rawUTC === "" || strlen($rawUTC) < 10) {
            IPS_LogMessage("WindMonitorPro", "⚠️ Kein gültiger UTC-Zeitstempel im metadata gefunden");
            return gmdate("Y-m-d H:i") . " (Fallback UTC)";
        }

        try {
            $utc = new DateTime($rawUTC, new DateTimeZone('UTC'));
            $lokal = clone $utc;
            //$lokal->setTimezone(new DateTimeZone('Europe/Berlin'));
            $lokal->setTimezone($targetZone);
            return $lokal->format("Y-m-d H:i");
        } catch (Exception $e) {
            IPS_LogMessage("WindMonitorPro", "❌ Fehler bei MB-Model-Zeitwandlung: " . $e->getMessage());
            return gmdate("Y-m-d H:i") . " (Fehler)";
        }
    }

    public static function getAktuellenZeitIndex(array $times, DateTimeZone $zone): ?int {
        $now = new DateTime("now", $zone);
        foreach ($times as $i => $t) {
            $dt = DateTime::createFromFormat('Y-m-d H:i', $t);
            if ($dt && $dt >= $now) {
                return $i;
            }
        }
        return null;
    }

    public static function extrahiereWetterdaten(array $block, int $index): array {
        return [
            'wind'     => $block['windspeed_80m'][$index] ?? 0,
            'gust'     => $block['gust'][$index] ?? 0,
            'dir'      => $block['winddirection_80m'][$index] ?? 0,
            'pressure' => $block['surfaceairpressure'][$index] ?? 0,
            'density'  => $block['airdensity'][$index] ?? 0
        ];
    }

    public static function berechneDurchschnittswerte(array $block, int $index, int $steps): array {
        $speeds = array_map(
            fn($v) => self::berechneWindObjekt($v, self::$zielHoeheStandard, self::$referenzhoehe, self::$gelaendeAlpha),
            array_filter(array_slice($block['windspeed_80m'] ?? [], $index, $steps), 'is_numeric')
        );

        $gusts = array_map(
            fn($v) => self::berechneWindObjekt($v, self::$zielHoeheStandard, self::$referenzhoehe, self::$gelaendeAlpha),
            array_filter(array_slice($block['gust'] ?? [], $index, $steps), 'is_numeric')
        );

        $dirs = array_filter(array_slice($block['winddirection_80m'] ?? [], $index, $steps), 'is_numeric');

        return [
            'avgWind' => round(array_sum($speeds) / max(count($speeds), 1), 2),
            'maxWind' => round(max($speeds), 2),
            'maxGust' => round(max($gusts), 2),
            'avgDir'  => round(array_sum($dirs) / max(count($dirs), 1))
        ];
    }



    /**
     * Wandelt Windgeschwindigkeit von Referenzhöhe auf Zielhöhe um
     * @param float $vRef Geschwindigkeit in Referenzhöhe (m/s)
     * @param float $zRef Referenzhöhe (z. B. 80)
     * @param float $zZiel Zielhöhe (z. B. 8)
     * @param float $GelaendeAlpha Rauigkeit (z. B. 0.14)
     * @return float umgerechnete Geschwindigkeit (m/s)
     */
    public static function windUmrechnungSmart(float $vRef, float $zRef, float $zZiel, float $GelaendeAlpha): float {
        if ($zZiel <= 0 || $zRef <= 0 || $zZiel > $zRef) {
            return $vRef; // keine Umrechnung nötig
        }
        return $vRef * pow($zZiel / $zRef, $GelaendeAlpha);
    }


    
    /**
     * Wandelt Grad in Windrichtungstext um (z. B. „NO“)
     */
    public static function gradZuRichtung(float $grad): string {
        $richtungen = ["N", "NNO", "NO", "ONO", "O", "OSO", "SO", "SSO",
                       "S", "SSW", "SW", "WSW", "W", "WNW", "NW", "NNW"];
        $index = round(($grad % 360) / 22.5) % 16;
        return $richtungen[$index];
    }

    /**
     * Wandelt Grad in Symbolpfeil um (z. B. „↗“)
     */
    public static function gradZuPfeil(float $grad): string {
        $pfeile = ["↑", "↗", "→", "↘", "↓", "↙", "←", "↖"];
        $index = round(($grad % 360) / 45) % 8;
        return $pfeile[$index];
    }

    public static function kuerzelZuWinkelbereich(string $kuerzel): array {
    $map = [
        "N" => [337.5, 22.5],
        "NO" => [22.5, 67.5],
        "O" => [67.5, 112.5],
        "SO" => [112.5, 157.5],
        "S" => [157.5, 202.5],
        "SW" => [202.5, 247.5],
        "W" => [247.5, 292.5],
        "NW" => [292.5, 337.5]
    ];
    return $map[$kuerzel] ?? [0.0, 360.0];
    }

    public static function isValidKuerzel(string $kuerzel): bool {
        $valid = ["N", "NO", "O", "SO", "S", "SW", "W", "NW"];
        return in_array($kuerzel, $valid);
    }

    public static function richtungPasst(float $grad, array $kuerzelListe): bool {
    foreach ($kuerzelListe as $kuerzel) {
        if (!self::isValidKuerzel($kuerzel)) {
            continue;
        }

        list($minGrad, $maxGrad) = self::kuerzelZuWinkelbereich($kuerzel);

        $passt = ($minGrad < $maxGrad)
            ? ($grad >= $minGrad && $grad <= $maxGrad)
            : ($grad >= $minGrad || $grad <= $maxGrad);

        if ($passt) {
            return true;
        }
    }
    return false;
}

    public static function getSmartCurrent(array $data, float $zielHoehe = 8.0, float $GelaendeAlpha = 0.14): ?array {
        $refHoehe = $data['metadata']['height'] ?? 80.0;
        $cur = $data['data_current'] ?? null;
        if (!$cur || !isset($cur['windspeed'])) return null;

        return [
            'zeit'     => $cur['time'] ?? '',
            'istTag'   => ($cur['isdaylight'] ?? 0) == 1,
            'tempC'    => $cur['temperature'] ?? null,
            'wind_raw' => $cur['windspeed'] ?? null,
            'wind_korrigiert' => is_numeric($cur['windspeed'])
                ? self::windXmToYm($cur['windspeed'], $zielHoehe, $refHoehe, $GelaendeAlpha)
                : null,
            'icon'     => $cur['pictocode'] ?? null,
            'iconDetail' => $cur['pictocode_detailed'] ?? null,
            'quelle_m' => $refHoehe
        ];
    }

    public static function berechneWindObjekt(float $windReferenz, float $hoeheObjekt, float $hoeheReferenz = 80.0, float $GelaendeAlpha = 0.14): float {
        if ($hoeheObjekt <= 0.5) {
            $hoeheObjekt = 1.0;
        }
        return round($windReferenz * pow($hoeheObjekt / $hoeheReferenz, $GelaendeAlpha), 2);
    }

    //$windInObjHoehe = WindToolsHelper::windUmrechnungSmart($wind, WindToolsHelper::$referenzhoehe, WindToolsHelper::$zielHoeheStandard, WindToolsHelper::$gelaendeAlpha);
    public static function berechneSchutzstatusMitNachwirkung(
    float $windMS,
    float $gustMS,
    float $thresholdWind,
    float $thresholdGust,
    int $nachwirkMinuten,
    int $idstatusStr,
    int $idWarnWind,
    int $idWarnGust,
    string $objektName = "",
    float $zielHoehe,
    array $data

    ): void {
        $nachwirkSekunden = $nachwirkMinuten * 60;
        $jetzt = time();

        // --- Warnbedingungen prüfen ---
        $warnWind = $windMS >= $thresholdWind;
        $warnGust = $gustMS >= $thresholdGust;

        // --- Restnachwirkzeit auslesen und berechnen ---
        $restzeitJson = GetValueString($idstatusStr); // z.B. '{"restzeit":"09:45",...}'
        $status = @json_decode($restzeitJson, true);
        if (!is_array($status)) {
            $status = [];
        }
        $alteRestzeitSek = 0;
        if (isset($status['restzeit'])) {
            $zeitTeile = explode(':', $status['restzeit']);
            if (count($zeitTeile) === 2) {
                $alteRestzeitSek = intval($zeitTeile[0]) * 60 + intval($zeitTeile[1]);
            }
        }

        $letzterRestzeitTS = IPS_GetVariable($idstatusStr)['VariableUpdated'];
        $vergangen = $jetzt - $letzterRestzeitTS;
        $rest = max($alteRestzeitSek - $vergangen, 0);

        // --- Logik für Nachwirkzeit und Warnvariablen ---
        if ($warnWind || $warnGust) {
            $rest = $nachwirkSekunden;
            SetValueBoolean($idWarnWind, $warnWind);
            SetValueBoolean($idWarnGust, $warnGust);
        } elseif ($rest > 0) {
            // Nachwirkzeit läuft: NICHTS an den Warnvariablen ändern!
            // (Status bleibt wie beim letzten Auslösen)
        } else {
            SetValueBoolean($idWarnWind, false);
            SetValueBoolean($idWarnGust, false);
        }

        // --- Restzeit-String + Zusatzinfos als Array bauen ---
        $min = floor($rest / 60);
        $sek = $rest % 60;
        $restNachwirkText = sprintf("%02d:%02d", $min, $sek);

        $BoeGefahrVorschau = self::ermittleWindAufkommen($data, $thresholdGust, $zielHoehe);

        $StatusCheckValuesJson = [
            "objekt"      => ($objektName === null || $objektName === "") ? "" : $objektName,
            "hoehe"       => $zielHoehe,
            "restzeit"    => $restNachwirkText,
            "limitWind"   => round($thresholdWind,1),
            "wind"        => round($windMS, 1),            
            "limitBoe"    => round($thresholdGust,1),
            "boe"         => round($gustMS, 1),            
            "warnWind"    => GetValueBoolean($idWarnWind),
            "warnGust"    => GetValueBoolean($idWarnGust),
            "nachwirk"    => $nachwirkMinuten,
            "boeVorschau" => $BoeGefahrVorschau
        ];

        SetValueString($idstatusStr, json_encode($StatusCheckValuesJson));

        // --- Logging (optional) ---
        //IPS_LogMessage("WindMonitorPro",
        //    "📡 Nachwirkcheck($nachwirkMinuten min) '$objektName' Wind=$windMS Boe=$gustMS Schwellen=$thresholdWind/$thresholdGust WarnWind=" . (GetValueBoolean($idWarnWind) ? "JA" : "NEIN") . " WarnGust=" . (GetValueBoolean($idWarnGust) ? "JA" : "NEIN") . " Nachwirkzeit: $restNachwirkText"
        //);
    }

    public static function ermittleWindAufkommen(array $data, float $threshold, float $Objhoehe): string {
    /**
     * Sucht das erste über dem Schwellwert liegende Windereignis ab "jetzt".
     * Gibt JSON-String im Format {"datum":..., "uhrzeit":..., "wert":...} zurück (ggf. mit null-Werten).
     *
     * @param array $data      Prognosedaten mit "time" und "gust"
     * @param float $threshold Grenzwert
     * @param float $Objhoehe  Umrechnungsparameter
     * @return string          JSON-String
     */

        $result = ['datum' => null, 'uhrzeit' => null, 'wert' => null];

        if (empty($data["time"]) || empty($data["gust"])) {
            return json_encode($result);
        }

        $timezone = new DateTimeZone('Europe/Berlin');
        $now = (new DateTime('now', $timezone))->getTimestamp();

        foreach ($data["time"] as $i => $zeitStr) {
            $dt = DateTime::createFromFormat('Y-m-d H:i', $zeitStr, $timezone);
            if (!$dt) continue;
            $ts = $dt->getTimestamp();
            $boeInObjHoehe = WindToolsHelper::windUmrechnungSmart(
                $data["gust"][$i],
                WindToolsHelper::$referenzhoehe,
                $Objhoehe,
                WindToolsHelper::$gelaendeAlpha
            );
            if ($ts >= $now && $boeInObjHoehe >= $threshold) {
                $result = [
                    'datum' => $dt->format('d.m.Y'),
                    'uhrzeit' => $dt->format('H:i'),
                    'wert' => round($boeInObjHoehe, 2)
                ];
                break;
            }
        }
        return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }


    public static function erzeugeSchutzDashboardNeu(array $schutzArray, int $instanceID): string {
        

        $html = "<div style='font-family:sans-serif; padding:10px;'><h3>🧯 Schutzobjekt-Übersicht</h3><table style='font-size:14px; border-collapse:collapse;'>";

        $html .= "<tr style='font-weight:bold; background:#f0f0f0;'>
            <td style='padding:4px;'>📛 Name</td>
            <td style='padding:4px;'>📏 Höhe</td>
            <td style='padding:4px;'>🌬️ Wind</td>
            <td style='padding:4px;'>💥 Böe</td>
            <td style='padding:4px;'>🧭 Richtung</td>
            <td style='padding:4px;'>⚠️ Status</td>
            <td style='padding:4px;'>⏱️ Letzte Warnung</td>
            <td style='padding:4px;'>📊 Zähler</td>
            <td style='padding:4px;'>📈 Prognose</td>  <!-- NEU -->

        </tr>";

        /*
                $html .= "<tr style='font-weight:bold; background:#f0f0f0;'>
            <td style='padding:4px;'>📛 Name</td>
            <td style='padding:4px;'>📏 Höhe</td>
            <td style='padding:4px;'>🌬️ Wind</td>
            <td style='padding:4px;'>💥 Böe</td>
            <td style='padding:4px;'>🧭 Richtung</td>
            <td style='padding:4px;'>⚠️ Status</td>
            <td style='padding:4px;'>⏱️ Letzte Warnung</td>
            <td style='padding:4px;'>📊 Zähler</td>
            <td style='padding:4px;'>📊 LastWind</td>
            <td style='padding:4px;'>📊 LastBoe</td>
            <td style='padding:4px;'>📊 RestZeit</td>

        </tr>";


        Mit richtigen Überschriften würde das so aussehen:
        $html .= "<tr style='font-weight:bold; background:#f0f0f0;'>
            <th style='padding:4px;'>📛 Name</th>
            <th style='padding:4px;'>📏 Höhe</th>
            ...
        </tr>";
        */

        foreach ($schutzArray as $objekt) {
            $label = $objekt["Label"] ?? "–";
            $hoehe = $objekt["Hoehe"] ?? "–";

            $vid = @IPS_GetObjectIDByIdent("Warnung_" . preg_replace('/\W+/', '_', $label), $instanceID);
            //$wind = $vid !== false ? GetValueFormatted($vid) : "–";
            $wind = ($vid !== false && IPS_VariableExists($vid)) ? GetValueFormatted($vid) : "–";
            $warnung = ($vid !== false && GetValueBoolean($vid));
            $status = $warnung
                ? "<span style='color:#e74c3c;'>⚠️ Aktiv</span>"
                : "<span style='color:#2ecc71;'>✅ Inaktiv</span>";
            $richtung = $objekt["RichtungsKuerzelListe"] ?? "–";            

            $countID = @IPS_GetObjectIDByIdent("WarnCount_" . preg_replace('/\W+/', '_', $label), $instanceID);
                $zaehler = ($countID !== false && IPS_VariableExists($countID)) ? GetValueInteger($countID) : "–";

            $tsID = @IPS_GetObjectIDByIdent("LetzteWarnungTS_" . preg_replace('/\W+/', '_', $label), $instanceID);
                $tsText = ($tsID !== false && IPS_VariableExists($tsID)) ? date("H:i", GetValueInteger($tsID)) . " Uhr" : "–";


            //Json-Variable Schutzobjekt-Status laden 
            $vid = @IPS_GetObjectIDByIdent("Status_" . preg_replace('/\W+/', '_', $label), $instanceID);
            $JsonProperties = GetValueString($vid);    
            // JSON zu Array
            $properties = json_decode($JsonProperties, true);
            $JsonWindPrognose = $properties['boeVorschau'];
            $prognose = json_decode($JsonWindPrognose, true);
            $DatumPrognose = $prognose['datum'];
            $TimePrognose = $prognose['uhrzeit'];
            $WindPrognose = $prognose['wert'];






            //$wind = GetValueFormatted(@IPS_GetObjectIDByIdent("Warnung_" . preg_replace('/\W+/', '_', $label)));
            
            //$status = $wind === "true" ? "<span style='color:#e74c3c;'>⚠️ Aktiv</span>" : "<span style='color:#2ecc71;'>✅ Inaktiv</span>";
                // 🚀 Windprognose berechnen
                //"Windaufkommen ab $datum um $uhrzeit Uhr ({$warnwert} m/s)";
                //@IPS_GetObjectIDByIdent("Status_" . $ident),
    $WindPrognose = WindTools::ermittleWindAufkommen(
    //    $objekt['mbforecast'],
    //    $objekt['windschwelle'],
    //    $objekt['Hoehe']
    );


            $html .= "<tr>
                <td style='padding:4px;'>$label</td>
                <td style='padding:4px;'>$hoehe m</td>
                <td style='padding:4px;'>{$objekt["MinWind"]} m/s</td>
                <td style='padding:4px;'>{$objekt["MinGust"]} m/s</td>
                <td style='padding:4px;'>$richtung</td>
                <td style='padding:4px;'>$status</td>
                <td style='padding:4px;'>$tsText</td>
                <td style='padding:4px;'>$zaehler</td>
                <td style='padding:4px;'>$WindPrognose</td>
            </tr>";
        }

        $html .= "</table></div>";
        return $html;
    }

    public static function erzeugeSchutzDashboard(array $schutzArray, int $instanceID): string {
        

        $html = "<div style='font-family:sans-serif; padding:10px;'><h3>🧯 Schutzobjekt-Übersicht</h3><table style='font-size:14px; border-collapse:collapse;'>";

        $html .= "<tr style='font-weight:bold; background:#f0f0f0;'>
            <td style='padding:4px;'>📛 Name</td>
            <td style='padding:4px;'>📏 Höhe</td>
            <td style='padding:4px;'>🌬️ Wind</td>
            <td style='padding:4px;'>💥 Böe</td>
            <td style='padding:4px;'>🧭 Richtung</td>
            <td style='padding:4px;'>⚠️ Status</td>
            <td style='padding:4px;'>⏱️ Letzte Warnung</td>
            <td style='padding:4px;'>📊 Zähler</td>

        </tr>";

        /*
                $html .= "<tr style='font-weight:bold; background:#f0f0f0;'>
            <td style='padding:4px;'>📛 Name</td>
            <td style='padding:4px;'>📏 Höhe</td>
            <td style='padding:4px;'>🌬️ Wind</td>
            <td style='padding:4px;'>💥 Böe</td>
            <td style='padding:4px;'>🧭 Richtung</td>
            <td style='padding:4px;'>⚠️ Status</td>
            <td style='padding:4px;'>⏱️ Letzte Warnung</td>
            <td style='padding:4px;'>📊 Zähler</td>
            <td style='padding:4px;'>📊 LastWind</td>
            <td style='padding:4px;'>📊 LastBoe</td>
            <td style='padding:4px;'>📊 RestZeit</td>

        </tr>";


        Mit richtigen Überschriften würde das so aussehen:
        $html .= "<tr style='font-weight:bold; background:#f0f0f0;'>
            <th style='padding:4px;'>📛 Name</th>
            <th style='padding:4px;'>📏 Höhe</th>
            ...
        </tr>";
        */

        foreach ($schutzArray as $objekt) {
            $label = $objekt["Label"] ?? "–";
            $hoehe = $objekt["Hoehe"] ?? "–";

            $vid = @IPS_GetObjectIDByIdent("Warnung_" . preg_replace('/\W+/', '_', $label), $instanceID);
            //$wind = $vid !== false ? GetValueFormatted($vid) : "–";
            $wind = ($vid !== false && IPS_VariableExists($vid)) ? GetValueFormatted($vid) : "–";
            $warnung = ($vid !== false && GetValueBoolean($vid));
            $status = $warnung
                ? "<span style='color:#e74c3c;'>⚠️ Aktiv</span>"
                : "<span style='color:#2ecc71;'>✅ Inaktiv</span>";
            $richtung = $objekt["RichtungsKuerzelListe"] ?? "–";            

            $countID = @IPS_GetObjectIDByIdent("WarnCount_" . preg_replace('/\W+/', '_', $label), $instanceID);
                $zaehler = ($countID !== false && IPS_VariableExists($countID)) ? GetValueInteger($countID) : "–";

            $tsID = @IPS_GetObjectIDByIdent("LetzteWarnungTS_" . preg_replace('/\W+/', '_', $label), $instanceID);
                $tsText = ($tsID !== false && IPS_VariableExists($tsID)) ? date("H:i", GetValueInteger($tsID)) . " Uhr" : "–";

            //$wind = GetValueFormatted(@IPS_GetObjectIDByIdent("Warnung_" . preg_replace('/\W+/', '_', $label)));
            
            //$status = $wind === "true" ? "<span style='color:#e74c3c;'>⚠️ Aktiv</span>" : "<span style='color:#2ecc71;'>✅ Inaktiv</span>";

            //Json-Variable Schutzobjekt-Status laden 
            $vid = @IPS_GetObjectIDByIdent("Status_" . preg_replace('/\W+/', '_', $label), $instanceID);
            $JsonProperties = GetValueString($vid);    
            // JSON zu Array
            $properties = json_decode($JsonProperties, true);
            $JsonWindPrognose = $properties['boeVorschau'];
            $prognose = json_decode($JsonWindPrognose, true);
            $DatumPrognose = $prognose['datum'] ?? '–';
            $TimePrognose  = $prognose['uhrzeit'] ?? '–';
            $WindPrognose  = isset($prognose['wert']) && $prognose['wert'] !== null ? number_format($prognose['wert'], 2, ',', '') : '–';

            $html .= "<tr>
                <td style='padding:4px;'>$label</td>
                <td style='padding:4px;'>$hoehe m</td>
                <td style='padding:4px;'>{$objekt["MinWind"]} m/s</td>
                <td style='padding:4px;'>{$objekt["MinGust"]} m/s</td>
                <td style='padding:4px;'>$richtung</td>
                <td style='padding:4px;'>$status</td>
                <td style='padding:4px;'>$tsText</td>
                <td style='padding:4px;'>$zaehler</td>

            </tr>";

            $html .= "<tr>
            <td colspan='8' style='padding:4px 16px; font-size:13px; background:#f8f8f8; color:#333;'>
            <span style='opacity:.7;'>🌬️ Prognose für Limitüberschreitung:</span>
            <span style='margin-left:20px;'>am Datum: <b>$DatumPrognose</b></span>
            <span style='margin-left:20px;'> um Uhrzeit: <b>$TimePrognose</b></span>
            <span style='margin-left:20px;'>mit Wert: <b>$WindPrognose m/s</b></span>
            </td>
            </td>
        </tr>";
        }

        $html .= "</table></div>";
        return $html;
    }


    /**
     * Optional: Umrechnung °C in gefühlte Temperatur o. ä.
     */
    // public static function irgendwas(...) { ... }
}
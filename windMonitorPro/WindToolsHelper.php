
<?php

class WindToolsHelper
{
    // 🔧 Konfigurationseinstellungen
    public static float $gelaendeAlpha = 0.14;
    public static float $referenzHoehe = 80.0;
    public static float $zielHoeheStandard = 10.0;

    public static function setKonfiguration(float $alpha, float $ref, float $ziel, string $typ = "logarithmisch"): void {
        self::$gelaendeAlpha     = $alpha;
        self::$referenzHoehe     = $ref;
        self::$zielHoeheStandard = $ziel;
    }

    public static function windXmToYm(float $vRef, float $zZiel, float $zRef = 80.0, float $GelaendeAlpha = 0.14): float {
        return $vRef * pow($zZiel / $zRef, $GelaendeAlpha);
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
            fn($v) => self::berechneWindObjekt($v, self::$zielHoeheStandard, self::$referenzHoehe, self::$gelaendeAlpha),
            array_filter(array_slice($block['windspeed_80m'] ?? [], $index, $steps), 'is_numeric')
        );

        $gusts = array_map(
            fn($v) => self::berechneWindObjekt($v, self::$zielHoeheStandard, self::$referenzHoehe, self::$gelaendeAlpha),
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

    public static function gradZuRichtung(float $grad): string {
        $richtungen = ["N", "NNO", "NO", "ONO", "O", "OSO", "SO", "SSO",
                       "S", "SSW", "SW", "WSW", "W", "WNW", "NW", "NNW"];
        $index = round(($grad % 360) / 22.5) % 16;
        return $richtungen[$index];
    }

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

    public static function getSmartCurrent(array $data, float $zielHoehe = 8.0, float $GelaendeAlpha = 0.14): ?array {
        $refHoehe = $data['metadata']['height'] ?? 80.0;
        $cur = $data['data_current'] ?? null;
        if (!$cur || !isset($cur['windspeed'])) return null;

        return [
            'zeit'            => $cur['time'] ?? '',
            'istTag'          => ($cur['isdaylight'] ?? 0) == 1,
            'tempC'           => $cur['temperature'] ?? null,
            'wind_raw'        => $cur['windspeed'] ?? null,
            'wind_korrigiert' => is_numeric($cur['windspeed'])
                ? self::windXmToYm($cur['windspeed'], $zielHoehe, $refHoehe, $GelaendeAlpha)
                : null,
            'icon'            => $cur['pictocode'] ?? null,
            'iconDetail'      => $cur['pictocode_detailed'] ?? null,
            'quelle_m'        => $refHoehe
        ];
    }

    public static function berechneWindObjekt(float $windReferenz, float $hoeheObjekt, float $hoeheReferenz = 80.0, float $GelaendeAlpha = 0.14): float {
        if ($hoeheObjekt <= 0.5) {
            $hoeheObjekt = 1.0;
        }
        return round($windReferenz * pow($hoeheObjekt / $hoeheReferenz, $GelaendeAlpha), 2);
    }

    public static function berechneSchutzstatusMitNachwirkung(
        float $windMS,
        float $gustMS,
        float $thresholdWind,
        float $thresholdGust,
        int $nachwirkSekunden,
        int $idWarnWind,
        int $idWarnGust,
        int $idLetzteWarnungTS,
        int $idSchutzAktiv
    ): void {
        $jetzt = time();
        $warnWind = $windMS >= $thresholdWind;
        $warnGust = $gustMS >= $thresholdGust;

        SetValueBoolean($idWarnWind, $warnWind);
        SetValueBoolean($idWarnGust, $warnGust);

        if ($warnWind || $warnGust) {
            SetValueInteger($idLetzteWarnungTS, $jetzt);
        }

        $letzteWarnung = GetValueInteger($idLetzteWarnungTS);
        $schutzAktiv = ($jetzt - $letzteWarnung) <= $nachwirkSekunden;

        SetValueBoolean($idSchutzAktiv, $schutzAktiv);
        // Optionales Logging zur Diagnose
        IPS_LogMessage(
            "WindMonitorPro",
            "Nachwirkprüfung → Wind=$windMS Böe=$gustMS Schwellen=$thresholdWind/$thresholdGust SchutzAktiv=" . ($schutzAktiv ? "JA" : "NEIN")
        );
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
        }

        $html .= "</table></div>";
        return $html;
    }

    /**
     * Optional: Umrechnung °C in gefühlte Temperatur o. ä.
     */
    // public static function irgendwas(...) { ... }
}




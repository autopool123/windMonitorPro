<?php

class WindToolsHelper
{
    /**
     * Wandelt Windgeschwindigkeit von Referenzhöhe auf Zielhöhe um
     * @param float $vRef Geschwindigkeit in Referenzhöhe (m/s)
     * @param float $zRef Referenzhöhe (z. B. 80)
     * @param float $zZiel Zielhöhe (z. B. 8)
     * @param float $alpha Rauigkeit (z. B. 0.22)
     * @return float umgerechnete Geschwindigkeit (m/s)
     */
    public static function windUmrechnungSmart(float $vRef, float $zRef, float $zZiel, float $alpha): float {
        if ($zZiel <= 0 || $zRef <= 0 || $zZiel > $zRef) {
            return $vRef; // keine Umrechnung nötig
        }
        return $vRef * pow($zZiel / $zRef, $alpha);
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

    function kuerzelZuWinkelbereich(string $kuerzel): array {
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

    function isValidKuerzel(string $kuerzel): bool {
        $valid = ["N", "NO", "O", "SO", "S", "SW", "W", "NW"];
        return in_array($kuerzel, $valid);
    }



    /**
     * Optional: Umrechnung °C in gefühlte Temperatur o. ä.
     */
    // public static function irgendwas(...) { ... }
}






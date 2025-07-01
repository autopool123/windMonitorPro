<?php

require_once(__DIR__ . "/WindToolsHelper.php"); // ⬅️ Dein Helferlein (kommt später)

class windMonitorPro extends IPSModule {

    public function Create() {
        parent::Create(); // 🧬 Pflicht: Symcon-Basisklasse initialisieren

        // Beispiel: Variable für Windgeschwindigkeit
        $this->RegisterVariableFloat("Wind80m", "Windgeschwindigkeit (80 m)", "WindPro.Speed.1");

        // Weitere folgen später…
    }

    public function ApplyChanges() {
        parent::ApplyChanges(); // 🔁 Pflicht: sorgt für Aktualisierung nach Änderungen

        // Variablenprofile erstellen
        if (!IPS_VariableProfileExists("WindPro.Speed.1")) {
            IPS_CreateVariableProfile("WindPro.Speed.1", VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileDigits("WindPro.Speed.1", 1);
            IPS_SetVariableProfileText("WindPro.Speed.1", "", " km/h");
            IPS_SetVariableProfileIcon("WindPro.Speed.1", "WindSpeed");
        }
    }

    // Beispielmethode
    public function UpdateWindSpeed(float $value) {
        SetValue($this->GetIDForIdent("Wind80m"), $value);
    }
}
?>
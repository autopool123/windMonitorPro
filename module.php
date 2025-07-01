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

    public function UpdateFromMeteoblue() {
    // 🧾 1. Einstellungen aus dem Modul lesen
    $apiKey = $this->ReadPropertyString("APIKey");
    $lat = $this->ReadPropertyFloat("Latitude");
    $lon = $this->ReadPropertyFloat("Longitude");
    $alti = $this->ReadPropertyInteger("Altitude");

    $zielhoehe = $this->ReadPropertyFloat("Zielhoehe");
    $zRef = $this->ReadPropertyInteger("Referenzhoehe");
    $alpha = $this->ReadPropertyFloat("Alpha");

    // 🧩 2. URL bauen
    $url = "https://my.meteoblue.com/packages/basic-1h_wind-15min,current" .
           "?lat=$lat&lon=$lon&altitude=$alti&apikey=$apiKey&format=json";

    // 🔄 3. Daten abrufen
    $json = @file_get_contents($url);
    if (!$json) {
        IPS_LogMessage("WindMonitorPro", "❌ meteoblue-Datenabruf fehlgeschlagen");
        return;
    }

    $data = json_decode($json, true);
    if (!$data || !isset($data['data_current']['time'][0])) {
        IPS_LogMessage("WindMonitorPro", "❌ Ungültige Datenstruktur");
        return;
    }

    // 🔍 4. Aktuelle Werte extrahieren
    $wind80 = $data["data_xmin"]["windspeed_80m"][0] ?? 0;
    $gust80 = $data["data_xmin"]["gust"][0] ?? 0;
    $winddir = $data["data_xmin"]["winddirection_80m"][0] ?? 0;
    $airpressure = $data["data_xmin"]["surfaceairpressure"][0] ?? 0;
    $airdensity = $data["data_xmin"]["airdensity"][0] ?? 0;

    $temp = $data["data_current"]["temperature"][0] ?? 0;
    $isDay = $data["data_current"]["isdaylight"][0] ?? false;
    $zeit = $data["data_current"]["time"][0] ?? "";
    $uv = $data["data_1h"]["uvindex"][0] ?? 0;

    // 💾 5. Variablen aktualisieren
    SetValue($this->GetIDForIdent("Wind80m"), round($wind80 * 3.6, 1));  // m/s → km/h
    SetValue($this->GetIDForIdent("Gust80m"), round($gust80 * 3.6, 1));
    SetValue($this->GetIDForIdent("WindDirection80m"), (int) $winddir);
    SetValue($this->GetIDForIdent("AirPressure"), $airpressure);
    SetValue($this->GetIDForIdent("AirDensity"), round($airdensity, 3));
    SetValue($this->GetIDForIdent("CurrentTemperature"), $temp);
    SetValue($this->GetIDForIdent("IsDaylight"), (bool) $isDay);
    SetValue($this->GetIDForIdent("CurrentTime"), $zeit);
    SetValue($this->GetIDForIdent("UVIndex"), $uv);

    // 🎯 6. Richtungstext & Symbol (aus Hilfsklasse)
    if (class_exists("WindToolsHelper")) {
        $txt = WindToolsHelper::gradZuRichtung($winddir);
        $arrow = WindToolsHelper::gradZuPfeil($winddir);

        SetValue($this->GetIDForIdent("WindDirText"), $txt);
        SetValue($this->GetIDForIdent("WindDirArrow"), $arrow);
    }

    IPS_LogMessage("WindMonitorPro", "✅ Daten aktualisiert für $zeit");
}


    // Beispielmethode
    public function UpdateWindSpeed(float $value) {
        SetValue($this->GetIDForIdent("Wind80m"), $value);
    }
}
?>
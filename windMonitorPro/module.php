<?php

require_once(__DIR__ . "/WindToolsHelper.php"); // ⬅️ Dein Helferlein 


class windMonitorPro extends IPSModule {

    public function Create() {
        parent::Create(); // 🧬 Pflicht: Symcon-Basisklasse initialisieren

        // Beispiel: Variable für Windgeschwindigkeit
        $this->RegisterPropertyInteger("UpdateInterval", 15);  // Minuten

        // 🧾 Modul-Konfiguration (aus form.json)
        $this->RegisterPropertyString("APIKey", "");
        $this->RegisterPropertyFloat("Latitude", 49.9842);
        $this->RegisterPropertyFloat("Longitude", 8.2791);
        $this->RegisterPropertyInteger("Altitude", 223);
        $this->RegisterPropertyFloat("Zielhoehe", 8.0);
        $this->RegisterPropertyInteger("Referenzhoehe", 80);
        $this->RegisterPropertyFloat("Alpha", 0.22);
        $this->RegisterPropertyBoolean("Aktiv", true);

        // 📦 Einstellungen für das Abruf-/Auswerteverhalten
        $this->RegisterPropertyString("Modus", "fetch"); // "fetch" oder "readfile"
        $this->RegisterPropertyString("Dateipfad", "/var/lib/symcon/user/winddata_15min.json");
        $this->RegisterPropertyInteger("StringVarID", 0); // Optional: ~TextBox-ID

    }

        // Weitere folgen später…

    public function ApplyChanges() {
        parent::ApplyChanges(); // 🔁 Pflicht: sorgt für Aktualisierung nach Änderungen

        // Variablenprofile erstellen



        // Wind-Geschwindigkeit (bereits vorhanden, zur Vollständigkeit)
        if (!IPS_VariableProfileExists("WindPro.Speed.1")) {
            IPS_CreateVariableProfile("WindPro.Speed.1", VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileDigits("WindPro.Speed.1", 1);
            IPS_SetVariableProfileText("WindPro.Speed.1", "", " km/h");
            IPS_SetVariableProfileIcon("WindPro.Speed.1", "WindSpeed");
        }

        // Windrichtung in Grad
        if (!IPS_VariableProfileExists("WindPro.Direction.Degree")) {
            IPS_CreateVariableProfile("WindPro.Direction.Degree", VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText("WindPro.Direction.Degree", "", "°");
            IPS_SetVariableProfileIcon("WindPro.Direction.Degree", "WindDirection");
        }

        // Luftdruck in hPa
        if (!IPS_VariableProfileExists("~AirPressure.F")) {
            IPS_CreateVariableProfile("~AirPressure.F", VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText("~AirPressure.F", "", " hPa");
            IPS_SetVariableProfileDigits("~AirPressure.F", 1);
            IPS_SetVariableProfileIcon("~AirPressure.F", "Gauge");
        }

        // Luftdichte – eigenes Profil
        if (!IPS_VariableProfileExists("~Density")) {
            IPS_CreateVariableProfile("~Density", VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText("~Density", "", " kg/m³");
            IPS_SetVariableProfileDigits("~Density", 3);
            IPS_SetVariableProfileIcon("~Density", "Gauge");
        }

        // Temperatur – Standardprofil
        if (!IPS_VariableProfileExists("~Temperature")) {
            IPS_CreateVariableProfile("~Temperature", VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText("~Temperature", "", " °C");
            IPS_SetVariableProfileDigits("~Temperature", 1);
            IPS_SetVariableProfileIcon("~Temperature", "Temperature");
        }









        $this->RegisterVariableFloat("Wind80m", "Windgeschwindigkeit (80 m)", "WindPro.Speed.1");
        $this->RegisterVariableFloat("Gust80m", "Böe (80 m)", "WindPro.Speed.1");
        $this->RegisterVariableInteger("WindDirection80m", "Windrichtung (80 m)", "WindPro.Direction.Degree");
        $this->RegisterVariableFloat("AirPressure", "Luftdruck", "~AirPressure.F");
        $this->RegisterVariableFloat("AirDensity", "Luftdichte", "~Density");
        $this->RegisterVariableFloat("CurrentTemperature", "Temperatur", "~Temperature");
        $this->RegisterVariableBoolean("IsDaylight", "Tageslicht", "");
        $this->RegisterVariableString("CurrentTime", "Zeitstempel", "");
        $this->RegisterVariableInteger("UVIndex", "UV-Index", "");
        $this->RegisterVariableString("WindDirText", "Windrichtung (Text)", "");
        $this->RegisterVariableString("WindDirArrow", "Windrichtung (Symbol)", "");








        

        // Timer erzeugen (bei Modul-Reload oder Property-Änderung)
        $this->RegisterTimer("FetchTimer", 0, 'WMP_UpdateFromMeteoblue($_IPS[\'TARGET\']);');

        // Intervall aus Property lesen
        $interval = $this->ReadPropertyInteger("UpdateInterval");
        if ($interval > 0) {
            $this->SetTimerInterval("FetchTimer", $interval * 60 * 1000); // in ms
        } else {
            $this->SetTimerInterval("FetchTimer", 0); // deaktivieren
        }

    }


    public function ReadFromFileAndUpdate(): void {
        $pfad = $this->ReadPropertyString("Dateipfad");
        $logtag = "WindReader";

        if (!file_exists($pfad)) {
            IPS_LogMessage($logtag, "❌ Datei nicht gefunden: $pfad");
            return;
        }

        $json = @file_get_contents($pfad);
        if (!$json) {
            IPS_LogMessage($logtag, "❌ Datei konnte nicht gelesen werden: $pfad");
            return;
        }

        $data = json_decode($json, true);
        if (!$data || !isset($data['data_current']['time'][0])) {
            IPS_LogMessage($logtag, "❌ Ungültige oder unvollständige JSON-Struktur");
            return;
        }

        // 🔍 Aktuelle Werte extrahieren
        $wind80 = $data["data_xmin"]["windspeed_80m"][0] ?? 0;
        $gust80 = $data["data_xmin"]["gust"][0] ?? 0;
        $winddir = $data["data_xmin"]["winddirection_80m"][0] ?? 0;
        $airpressure = $data["data_xmin"]["surfaceairpressure"][0] ?? 0;
        $airdensity = $data["data_xmin"]["airdensity"][0] ?? 0;

        $temp = $data["data_current"]["temperature"][0] ?? 0;
        $isDay = $data["data_current"]["isdaylight"][0] ?? false;
        $zeit = $data["data_current"]["time"][0] ?? "";
        $uv = $data["data_1h"]["uvindex"][0] ?? 0;

        // 💾 Variablen aktualisieren
        SetValue($this->GetIDForIdent("Wind80m"), round($wind80 * 3.6, 1));
        SetValue($this->GetIDForIdent("Gust80m"), round($gust80 * 3.6, 1));
        SetValue($this->GetIDForIdent("WindDirection80m"), (int) $winddir);
        SetValue($this->GetIDForIdent("AirPressure"), $airpressure);
        SetValue($this->GetIDForIdent("AirDensity"), round($airdensity, 3));
        SetValue($this->GetIDForIdent("CurrentTemperature"), $temp);
        SetValue($this->GetIDForIdent("IsDaylight"), (bool) $isDay);
        SetValue($this->GetIDForIdent("CurrentTime"), $zeit);
        SetValue($this->GetIDForIdent("UVIndex"), $uv);

        // 🎯 Richtungstext & Pfeil
        if (class_exists("WindToolsHelper")) {
            $txt = WindToolsHelper::gradZuRichtung($winddir);
            $arrow = WindToolsHelper::gradZuPfeil($winddir);
            SetValue($this->GetIDForIdent("WindDirText"), $txt);
            SetValue($this->GetIDForIdent("WindDirArrow"), $arrow);
        }

        IPS_LogMessage($logtag, "✅ Datei erfolgreich verarbeitet – Zeitstempel: $zeit");
    }





    
    public function UpdateFromMeteoblue() {
        $modus = $this->ReadPropertyString("Modus");
        $logtag = "WindMonitorPro";

        if ($modus == "fetch") {
            IPS_LogMessage($logtag, "🔁 Modus: Daten von meteoblue abrufen & verarbeiten");
            $this->FetchAndStoreMeteoblueData();         // Holt Daten von meteoblue und speichert sie
            $this->ReadFromFileAndUpdate();              // Liest gespeicherte Datei und aktualisiert Variablen
        } elseif ($modus == "readfile") {
            IPS_LogMessage($logtag, "📂 Modus: Nur lokale Datei verarbeiten");
            $this->ReadFromFileAndUpdate();              // Nur aus Datei lesen (keine API!)
        } else {
            IPS_LogMessage($logtag, "❌ Unbekannter Modus: '$modus'");
        }
    }






    // Beispielmethode
    public function UpdateWindSpeed(float $value) {
        SetValue($this->GetIDForIdent("Wind80m"), $value);
    }

    public function FetchAndStoreMeteoblueData(): void {
        $logtag = "WindFetcher";

        $apiKey = $this->ReadPropertyString("APIKey");
        $lat = $this->ReadPropertyFloat("Latitude");
        $lon = $this->ReadPropertyFloat("Longitude");
        $alti = $this->ReadPropertyInteger("Altitude");
        $file = $this->ReadPropertyString("Dateipfad");
        $stringVar = $this->ReadPropertyInteger("StringVarID");

        if ($apiKey == "") {
            IPS_LogMessage($logtag, "❌ Kein API-Key gesetzt");
            return;
        }

        // 📡 URL aufbauen
        $url = "https://my.meteoblue.com/packages/basic-1h_wind-15min,current" .
            "?lat=$lat&lon=$lon&altitude=$alti&apikey=$apiKey&format=json";

        // 🌐 Daten abrufen
        $json = @file_get_contents($url);
        if (!$json) {
            IPS_LogMessage($logtag, "❌ meteoblue-Datenabruf fehlgeschlagen");
            return;
        }

        // 💾 Speichern
        $ok = @file_put_contents($file, $json);
        if (!$ok) {
            IPS_LogMessage($logtag, "❌ Speichern nach $file fehlgeschlagen");
            return;
        }

        // 🗒️ Optional: String-Variable aktualisieren
        if (IPS_VariableExists($stringVar) && $stringVar > 0) {
            SetValueString($stringVar, $json);
        }

        IPS_LogMessage($logtag, "✅ Daten von meteoblue gespeichert unter: $file");
    }


}
?>
<?php

require_once(__DIR__ . "/WindToolsHelper.php"); // ⬅️ Dein Helferlein 


class windMonitorPro extends IPSModule {

    public function Create() {
        parent::Create(); // 🧬 Pflicht: Symcon-Basisklasse initialisieren

       
        // 🧾 Modul-Konfiguration (aus form.json)
        $this->RegisterPropertyString("APIKey", "");
        $this->RegisterPropertyFloat("Latitude", 49.9842);
        $this->RegisterPropertyFloat("Longitude", 8.2791);
        $this->RegisterPropertyInteger("Altitude", 223);
        $this->RegisterPropertyFloat("Zielhoehe", 8.0);
        $this->RegisterPropertyInteger("Referenzhoehe", 80);
        $this->RegisterPropertyFloat("Alpha", 0.22);
        $this->RegisterPropertyBoolean("Aktiv", true);
        $this->RegisterPropertyString("Schutzobjekte", "[]"); // Leere Liste initial


        $this->RegisterPropertyInteger("FetchIntervall", 120);  // z. B. alle 2h
        $this->RegisterPropertyInteger("ReadIntervall", 15);    // alle 15min
        $this->RegisterPropertyInteger("NachwirkzeitMin", 10);  // Nachwirkzeit in Minuten



        // 📦 Einstellungen für das Abruf-/Auswerteverhalten
        $this->RegisterPropertyString("Modus", "fetch"); // "fetch" oder "readfile"
        $this->RegisterPropertyString("Dateipfad", "/var/lib/symcon/user/winddata_15min.json");
        $this->RegisterPropertyInteger("StringVarID", 0); // Optional: ~TextBox-ID

        // Timer für API-Abruf (meteoblue)
        $this->RegisterTimer("FetchTimer", 0, 'WMP_FetchMeteoblue($_IPS[\'TARGET\']);');
        // Timer für Datei-Auswertung
        $this->RegisterTimer("ReadTimer", 0, 'WMP_ReadFromFile($_IPS[\'TARGET\']);');

        

    }

        // Weitere folgen später…

public function ApplyChanges() {
    parent::ApplyChanges();

    // 🔧 Profile erstellen
    if (!IPS_VariableProfileExists("WindPro.Speed.1")) {
        IPS_CreateVariableProfile("WindPro.Speed.1", VARIABLETYPE_FLOAT);
        IPS_SetVariableProfileDigits("WindPro.Speed.1", 1);
        IPS_SetVariableProfileText("WindPro.Speed.1", "", " km/h");
        IPS_SetVariableProfileIcon("WindPro.Speed.1", "WindSpeed");
    }

    if (!IPS_VariableProfileExists("WindPro.Direction.Degree")) {
        IPS_CreateVariableProfile("WindPro.Direction.Degree", VARIABLETYPE_INTEGER);
        IPS_SetVariableProfileText("WindPro.Direction.Degree", "", "°");
        IPS_SetVariableProfileIcon("WindPro.Direction.Degree", "WindDirection");
    }

    if (!IPS_VariableProfileExists("WMP.AirPressure")) {
        IPS_CreateVariableProfile("WMP.AirPressure", VARIABLETYPE_FLOAT);
        IPS_SetVariableProfileText("WMP.AirPressure", "", " hPa");
        IPS_SetVariableProfileDigits("WMP.AirPressure", 1);
        IPS_SetVariableProfileIcon("WMP.AirPressure", "Gauge");
    }

    if (!IPS_VariableProfileExists("WMP.Density")) {
        IPS_CreateVariableProfile("WMP.Density", VARIABLETYPE_FLOAT);
        IPS_SetVariableProfileText("WMP.Density", "", " kg/m³");
        IPS_SetVariableProfileDigits("WMP.Density", 3);
        IPS_SetVariableProfileIcon("WMP.Density", "Gauge");
    }

    if (!IPS_VariableProfileExists("WMP.Temperature")) {
        IPS_CreateVariableProfile("WMP.Temperature", VARIABLETYPE_FLOAT);
        IPS_SetVariableProfileText("WMP.Temperature", "", " °C");
        IPS_SetVariableProfileDigits("WMP.Temperature", 1);
        IPS_SetVariableProfileIcon("WMP.Temperature", "Temperature");
    }

    // 🧾 Variablen registrieren
    $this->RegisterVariableFloat("Wind80m", "Windgeschwindigkeit (80 m)", "WindPro.Speed.1");
    $this->RegisterVariableFloat("Gust80m", "Böe (80 m)", "WindPro.Speed.1");
    $this->RegisterVariableInteger("WindDirection80m", "Windrichtung (80 m)", "WindPro.Direction.Degree");
    $this->RegisterVariableFloat("AirPressure", "Luftdruck", "WMP.AirPressure");
    $this->RegisterVariableFloat("AirDensity", "Luftdichte", "WMP.Density");
    $this->RegisterVariableFloat("CurrentTemperature", "Temperatur", "WMP.Temperature");
    $this->RegisterVariableBoolean("IsDaylight", "Tageslicht", "");
    $this->RegisterVariableString("CurrentTime", "Zeitstempel", "");
    $this->RegisterVariableInteger("UVIndex", "UV-Index", "");
    $this->RegisterVariableString("WindDirText", "Windrichtung (Text)", "");
    $this->RegisterVariableString("WindDirArrow", "Windrichtung (Symbol)", "");
    $this->RegisterVariableInteger("LetzteWarnungTS", "Letzter Warnzeitpunkt", "");
    $this->RegisterVariableBoolean("WarnungAktiv", "Schutz aktiv", "~Alert");
    $this->RegisterVariableString("SchutzHTML", "Schutzstatus (HTML)", "~HTMLBox");
    $this->RegisterVariableString("LetzterFetch", "Letzter API-Abruf", "~TextBox");
    $this->RegisterVariableString("LetzteAuswertung", "Letzte Dateiverarbeitung", "~TextBox");
    $this->RegisterVariableString("NachwirkEnde", "Nachwirkzeit endet um", "~TextBox");
    $this->RegisterVariableBoolean("FetchDatenVeraltet", "Daten zu alt", "~Alert");


    //Abrufintervalle und Nachwirkzeit
    $this->RegisterVariableString("FetchIntervalInfo", "Abrufintervall (Info)", "~TextBox");
    $this->RegisterVariableString("ReadIntervalInfo", "Dateileseintervall (Info)", "~TextBox");
    $this->RegisterVariableString("NachwirkzeitInfo", "Nachwirkzeit (Info)", "~TextBox");
    // Werte aktualisieren
    SetValueString($this->GetIDForIdent("FetchIntervalInfo"), $this->ReadPropertyInteger("FetchIntervall") . " Minuten");
    SetValueString($this->GetIDForIdent("ReadIntervalInfo"), $this->ReadPropertyInteger("ReadIntervall") . " Minuten");
    SetValueString($this->GetIDForIdent("NachwirkzeitInfo"), $this->ReadPropertyInteger("NachwirkzeitMin") . " Minuten");




    // Timerinterval aus Properties berechnen
    $fetchMin = $this->ReadPropertyInteger("FetchIntervall");
    $readMin  = $this->ReadPropertyInteger("ReadIntervall");

    // Nur aktivieren, wenn Instanz "aktiv" ist
    if ($this->ReadPropertyBoolean("Aktiv")) {
        $this->SetTimerInterval("FetchTimer", $fetchMin * 60 * 1000);
        $this->SetTimerInterval("ReadTimer",  $readMin  * 60 * 1000);
    } else {
        $this->SetTimerInterval("FetchTimer", 0); // deaktivieren
        $this->SetTimerInterval("ReadTimer",  0);
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

        //Pruefung auf veraltetem Zeitstempel der Daten und setzen Sperrflag
        $fetchTS = strtotime($zeit);
        $jetztTS = time();
        $diffMin = ($jetztTS - $fetchTS) / 60;

        if ($diffMin > 30) {
            IPS_LogMessage("WindMonitorPro", "🛑 Warnung: Meteoblue-Daten sind älter als 30 Minuten ($diffMin min)");
            // Optional: Schutz deaktivieren
            SetValueBoolean($this->GetIDForIdent("WarnungAktiv"), false);

            // Optional: Sperrflag setzen
            if ($this->GetIDForIdent("FetchDatenVeraltet")) {
                SetValueBoolean($this->GetIDForIdent("FetchDatenVeraltet"), true);
            }
        }
        else {
            // Sperrflag zurücksetzen
            if ($this->GetIDForIdent("FetchDatenVeraltet")) {
                SetValueBoolean($this->GetIDForIdent("FetchDatenVeraltet"), false);
            }
        }






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


        $schutzArray = json_decode($this->ReadPropertyString("Schutzobjekte"), true);
        $richtung = $data["data_xmin"]["winddirection_80m"][0] ?? 0;
        $wind = $data["data_xmin"]["windspeed_80m"][0] ?? 0;
        $boe  = $data["data_xmin"]["gust"][0] ?? 0;

        foreach ($schutzArray as $eintrag) {
            $name = $eintrag["Label"];
            $minWind = floatval($eintrag["MinWind"]);
            $minGust = floatval($eintrag["MinGust"]);
            $kuerzel = $eintrag["RichtungKuerzel"] ?? "";
            list($minGrad, $maxGrad) = kuerzelZuWinkelbereich($kuerzel);

            $inSektor = ($minGrad < $maxGrad)
                ? ($richtung >= $minGrad && $richtung <= $maxGrad)
                : ($richtung >= $minGrad || $richtung <= $maxGrad);

            $warnung = $inSektor && ($wind >= $minWind || $boe >= $minGust);

            if ($warnung) {
                IPS_LogMessage("WindWarnung", "⚠️ Schutzobjekt '$name': Richtungsprüfung $richtung°, Wind=$wind m/s, Böe=$boe m/s");
                // Optional: individuelle Aktion oder Visualisierung pro Objekt
            }
        }




        // 🎯 Richtungstext & Pfeil
        if (class_exists("WindToolsHelper")) {
            $txt = WindToolsHelper::gradZuRichtung($winddir);
            $arrow = WindToolsHelper::gradZuPfeil($winddir);
            SetValue($this->GetIDForIdent("WindDirText"), $txt);
            SetValue($this->GetIDForIdent("WindDirArrow"), $arrow);

            // 🔐 Schutzlogik
            $windGrenze = 10.0; // Schwelle in m/s, individuell anpassbar
            $boeGrenze = 14.0;

            $warnungAktiv = ($wind80 >= $windGrenze || $gust80 >= $boeGrenze);
            $this->AktualisiereSchutzstatus($warnungAktiv, $winddir);
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

    public function AktualisiereSchutzstatus(bool $warnungGeradeAktiv, int $grad) {
        $nachwirkZeitSek = $this->ReadPropertyInteger("NachwirkzeitMin") * 60;
        $now = time();

        $lastTS = GetValueInteger($this->GetIDForIdent("LetzteWarnungTS"));
        $warAktiv = GetValueBoolean($this->GetIDForIdent("WarnungAktiv"));


        // ⏱️ Wenn neue Warnung → Zeitstempel setzen
        if ($warnungGeradeAktiv) {
            $lastTS = $now;
            SetValueInteger($this->GetIDForIdent("LetzteWarnungTS"), $lastTS);
        }



        // 🧠 Nachwirkzeit berücksichtigen
        $schutzAktiv = ($now - $lastTS) < $nachwirkZeitSek;

        // 🛡️ Schutzstatus setzen
        SetValueBoolean($this->GetIDForIdent("WarnungAktiv"), $schutzAktiv);

        $ablaufTS = $lastTS + $nachwirkZeitSek;
        $ablaufDT = (new DateTime("@$ablaufTS"))->setTimezone(new DateTimeZone('Europe/Berlin'))->format("d.m.Y H:i:s");
        SetValueString($this->GetIDForIdent("NachwirkEnde"), $ablaufDT);        

        // 🖼️ HTML-Ausgabe aktualisieren
        require_once(__DIR__ . "/WindToolsHelper.php"); // damit erzeugeSchutzHTML verfügbar ist

        $html = erzeugeSchutzHTML($schutzAktiv, $lastTS, $nachwirkZeitSek, $grad);
        SetValueString($this->GetIDForIdent("SchutzHTML"), $html);
}

    public function WMP_FetchMeteoblue() {
        $this->FetchAndStoreMeteoblueData(); // holt Daten & speichert JSON

        // Zeitpunkt setzen
        $now = (new DateTime("now", new DateTimeZone("Europe/Berlin")))->format("d.m.Y H:i:s");
        SetValueString($this->GetIDForIdent("LetzterFetch"), $now);
        }




    public function WMP_ReadFromFile() {
        $this->ReadFromFileAndUpdate(); // liest JSON & aktualisiert Variablen

        // Zeitpunkt setzen
        $now = (new DateTime("now", new DateTimeZone("Europe/Berlin")))->format("d.m.Y H:i:s");
        SetValueString($this->GetIDForIdent("LetzteAuswertung"), $now);
    }


}
?>
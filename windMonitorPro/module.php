<?php

require_once(__DIR__ . "/WindToolsHelper.php"); // ⬅️ Dein Helferlein 


class windMonitorPro extends IPSModule {

    public function Create() {
        parent::Create(); // 🧬 Pflicht: Symcon-Basisklasse initialisieren

       
        // 🧾 Modul-Konfiguration (aus form.json)
        $this->RegisterPropertyString("PackageSuffix", "basic-1h_wind-15min,current");
        $this->RegisterPropertyString("APIKey", "");
        $this->RegisterPropertyFloat("Latitude", 49.9842);
        $this->RegisterPropertyFloat("Longitude", 8.2791);
        $this->RegisterPropertyInteger("Altitude", 223);
        $this->RegisterPropertyFloat("Zielhoehe", 8.0);
        $this->RegisterPropertyInteger("Referenzhoehe", 80);
        $this->RegisterPropertyFloat("GelaendeAlpha", 0.14);

        $this->RegisterPropertyBoolean("Aktiv", true);
        $this->RegisterPropertyString("Schutzobjekte", "[]"); // Leere Liste initial


        $this->RegisterPropertyInteger("FetchIntervall", 120);  // z. B. alle 2h
        $this->RegisterPropertyInteger("ReadIntervall", 15);    // alle 15min
        $this->RegisterPropertyInteger("NachwirkzeitMin", 10);  // Nachwirkzeit in Minuten
        //$this->RegisterTimer("WindUpdateTimer", 0, 'IPS_RequestAction($_IPS["INSTANCE"], "UpdateWind", "");');




        // 📦 Einstellungen für das Abruf-/Auswerteverhalten
        //$this->RegisterPropertyString("Modus", "fetch"); // "fetch" oder "readfile" Relikt aus der ersten Version
        $this->RegisterPropertyString("Dateipfad", "/var/lib/symcon/user/winddata_15min.json");
        $this->RegisterPropertyInteger("StringVarID", 0); // Optional: ~TextBox-ID

        // Timer für API-Abruf (meteoblue)
        $this->RegisterTimer("FetchTimer", 0, 'WMP_FetchMeteoblue($_IPS[\'TARGET\']);');
        // Timer für Datei-Auswertung
        $this->RegisterTimer("ReadTimer", 0, 'WMP_ReadFromFile($_IPS[\'TARGET\']);');

        $this->RegisterVariableString("FetchJSON", "Letzter JSON-Download");
        $this->RegisterVariableString("SchutzStatusText", "🔍 Schutzstatus");
        $this->RegisterVariableString("CurrentTime", "Zeitstempel der Daten");
        $this->RegisterVariableString("UTC_ModelRun", "📦 UTC-Zeit der Modellgenerierung");

        $schutzArray = json_decode($this->ReadPropertyString("Schutzobjekte"), true);

        foreach ($schutzArray as $eintrag) {
            $name = $eintrag["Label"] ?? "Unbenannt";
            $ident = "Warnung_" . preg_replace('/\W+/', '_', $name);
            $txtIdent = "Status_" . preg_replace('/\W+/', '_', $name);

            if (@IPS_VariableExists($this->GetIDForIdent($ident))) {
                IPS_SetIcon($this->GetIDForIdent($ident), "Shield");
            }
            if (@IPS_VariableExists($this->GetIDForIdent($txtIdent))) {
                IPS_SetIcon($this->GetIDForIdent($txtIdent), "Alert");
            }
        }

        //$this->RegisterVariableString("SchutzDashboardHTML", "🧯 Schutzobjekt-Dashboard");
        //IPS_SetVariableCustomProfile($this->GetIDForIdent("SchutzDashboardHTML"), "~HTMLBox");

        //$this->RegisterVariableInteger("WarnCount_" . preg_replace('/\W+/', '_', $name), "⚠️ Warnzähler: $name");
        //$this->RegisterVariableInteger($countIdent, "⚠️ Warnzähler: $name");





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

    $vid = $this->GetIDForIdent("FetchJSON");
    IPS_SetIcon($vid, "Database");
    IPS_SetVariableCustomProfile($vid, "");
    IPS_SetHidden($vid, false); // oder true, wenn du sie intern hältst

    $vid = $this->GetIDForIdent("SchutzStatusText");
    IPS_SetIcon($vid, "Shield");
    $vid = $this->GetIDForIdent("CurrentTime");
    IPS_SetIcon($vid, "Clock"); 
    $vid = $this->GetIDForIdent("UTC_ModelRun");
    IPS_SetIcon($vid, "Database");
    //$vid = $this->GetIDForIdent($ident);
    //IPS_SetIcon($vid, "Shield");
    //$txtVid = $this->GetIDForIdent($txtIdent);
    //IPS_SetIcon($txtVid, "Alert"); // oder "Information"

    WindToolsHelper::setKonfiguration(
        $this->ReadPropertyFloat("GelaendeAlpha"),
        $this->ReadPropertyFloat("Referenzhoehe"),
        $this->ReadPropertyFloat("Zielhoehe"),
        "logarithmisch"
    );




    // 🧾 Variablen registrieren
    $this->RegisterVariableFloat("Wind80m", "Windgeschwindigkeit (80 m)", "WindPro.Speed.1");
    $this->RegisterVariableFloat("Gust80m", "Böe (80 m)", "WindPro.Speed.1");
    $this->RegisterVariableInteger("WindDirection80m", "Windrichtung (80 m)", "WindPro.Direction.Degree");
    $this->RegisterVariableFloat("AirPressure", "Luftdruck", "WMP.AirPressure");
    $this->RegisterVariableFloat("AirDensity", "Luftdichte", "WMP.Density");
    $this->RegisterVariableFloat("CurrentTemperature", "Temperatur", "WMP.Temperature");
    $this->RegisterVariableBoolean("IsDaylight", "Tageslicht", "");
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
    $this->RegisterVariableString("LetzteAktion", "Letzte Aktion");

    $this->RegisterVariableFloat("WindMS", "🌬️ Wind (m/s)");
    $this->RegisterVariableFloat("GustMS", "💥 Böe (m/s)");
    $this->RegisterVariableInteger("DirNow", "🧭 Richtung");
    $this->RegisterVariableString("SchutzDashboardHTML", "🧯 Schutz-Dashboard");
    IPS_SetVariableCustomProfile($this->GetIDForIdent("SchutzDashboardHTML"), "~HTMLBox");


    
    



    //Abrufintervalle und Nachwirkzeit
    $this->RegisterVariableString("FetchIntervalInfo", "Abrufintervall (Info)", "~TextBox");
    $this->RegisterVariableString("ReadIntervalInfo", "Dateileseintervall (Info)", "~TextBox");
    $this->RegisterVariableString("NachwirkzeitInfo", "Nachwirkzeit (Info)", "~TextBox");
    //$this->SetTimerInterval("WindUpdateTimer", 10 * 60 * 1000); // alle 10 Minuten

    // Werte aktualisieren
    SetValueString($this->GetIDForIdent("FetchIntervalInfo"), $this->ReadPropertyInteger("FetchIntervall") . " Minuten");
    SetValueString($this->GetIDForIdent("ReadIntervalInfo"), $this->ReadPropertyInteger("ReadIntervall") . " Minuten");
    SetValueString($this->GetIDForIdent("NachwirkzeitInfo"), $this->ReadPropertyInteger("NachwirkzeitMin") . " Minuten");
  

    //WindToolsHelper::$GelaendeAlpha = $this->ReadPropertyFloat("GelaendeAlpha");



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

    foreach (json_decode($this->ReadPropertyString("Schutzobjekte"), true) as $objekt) {
        $ident = "Warnung_" . preg_replace('/\W+/', '_', $objekt["Label"]);//generiere aus json Textobjekt den zugehörigen ident
        $vid = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($vid !== false) {
            IPS_SetIcon($vid, "Shield");
            IPS_SetVariableCustomProfile($vid, "~Alert"); // optionales Profil
            IPS_LogMessage("WindMonitorPro", "erzeugter Ident: $ident zu Var-ID: $vid");
        }
    }
    if ($this->ReadPropertyBoolean("MeteoAktiv")) {
    WMP_FetchMeteoblue($this->InstanceID);
}


}

public function RequestAction($Ident, $Value) {
    // 🔍 Logging für Analysezwecke
    IPS_LogMessage("WindMonitorPro", "⏱️ RequestAction erhalten: $Ident mit Wert=" . print_r($Value, true));

    // 🔀 Verteile an Aktion basierend auf Ident
    switch ($Ident) {
        case "UpdateMeteoBlue":
            return $this->UpdateFromMeteoblue();

        case "UpdateWind":
            return $this->ReadFromFileAndUpdate();

        case "ReloadCSV":
            return $this->ReloadCSVDatei();

        case "ResetStatus":
            return $this->ResetSchutzStatus();

        case "ClearWarnungen":
            return $this->WarnungsVariablenLeeren();

        case "SetGrenze":
            return $this->SetzeGrenzwert(floatval($Value));

        default:
            throw new Exception("⚠️ Ungültiger Aktion-Identifier: " . $Ident);
    }
}


    private function getLokaleModelzeit(array $data): string {
        $rawUTC = $data["metadata"]["modelrun_updatetime_utc"] ?? "";
        if ($rawUTC === "" || strlen($rawUTC) < 10) {
            IPS_LogMessage("WindMonitorPro", "⚠️ Kein gültiger UTC-Zeitstempel im metadata gefunden");
            return gmdate("Y-m-d H:i") . " (Fallback UTC)";
        }

        try {
            $utc = new DateTime($rawUTC, new DateTimeZone('UTC'));
            $lokal = clone $utc;
            $lokal->setTimezone(new DateTimeZone('Europe/Berlin'));
            return $lokal->format("Y-m-d H:i");
        } catch (Exception $e) {
            IPS_LogMessage("WindMonitorPro", "❌ Fehler bei Zeitwandlung: " . $e->getMessage());
            return gmdate("Y-m-d H:i") . " (Fehler)";
        }
}




//Funktion zum Auslesen der Speicherdatei imn welcher die zuletzt gefetchten MeteoBlue-Daten gespeichert wurden
public function ReadFromFileAndUpdate(): void {
    $json = $this->ReadMeteoJson(); // eigene Methode zum Laden
    if (!$json) {
        IPS_LogMessage("WindMonitorPro", "❌ Kein JSON verfügbar.");
        return;
    }

    $data = json_decode($json, true);
    $block = $data['data_xmin'] ?? null;
    if (!$block || !isset($block['time'])) return;

    $times = $block['time'];
    $zone = new DateTimeZone($data['metadata']['timezone_abbrevation'] ?? 'UTC');
    $index = WindToolsHelper::getAktuellenZeitIndex($times, $zone);
    if ($index === null) return;

    $timeText = $times[$index];
    SetValueString($this->GetIDForIdent("ZeitNow"), $timeText);
    SetValueString($this->GetIDForIdent("Zeitstempel"), $data['metadata']['modelrun_updatetime_utc'] ?? 'unbekannt');

    // Einzelwerte extrahieren
    $werte = WindToolsHelper::extrahiereWetterdaten($block, $index);
    $wind = WindToolsHelper::berechneWindObjekt($werte['wind'], WindToolsHelper::$zielHoeheStandard);
    $boe  = WindToolsHelper::berechneWindObjekt($werte['gust'], WindToolsHelper::$zielHoeheStandard);
    $richtung = $werte['dir'];

    SetValueFloat($this->GetIDForIdent("WindMS"), $wind);
    SetValueFloat($this->GetIDForIdent("GustMS"), $boe);
    SetValueInteger($this->GetIDForIdent("DirNow"), $richtung);

    // Durchschnittswerte berechnen
    $durchschnitt = WindToolsHelper::berechneDurchschnittswerte($block, $index, 4);
    SetValueFloat($this->GetIDForIdent("SpeedMS"), $durchschnitt['avgWind']);
    SetValueFloat($this->GetIDForIdent("SpeedMaxMS"), $durchschnitt['maxWind']);
    SetValueFloat($this->GetIDForIdent("GustMaxMS"), $durchschnitt['maxGust']);
    SetValueInteger($this->GetIDForIdent("DirGrad"), $durchschnitt['avgDir']);

    // Schutzobjekte prüfen
    $schutzArray = json_decode($this->ReadPropertyString("Schutzobjekte"), true);
    foreach ($schutzArray as $objekt) {
        $name = $objekt["Label"] ?? "Unbenannt";
        $ident = preg_replace('/\W+/', '_', $name);
        $minWind = $objekt["MinWind"] ?? 10.0;
        $minGust = $objekt["MinGust"] ?? 14.0;
        $richtungsliste = $objekt["RichtungsKuerzelListe"] ?? "";

        $inSektor = WindToolsHelper::richtungPasst($richtung, $richtungsliste);
        $warnung = $inSektor && ($wind >= $minWind || $boe >= $minGust);

        WindToolsHelper::berechneSchutzstatusMitNachwirkung(
            $wind,
            $boe,
            $minWind,
            $minGust,
            600,
            $this->GetIDForIdent("Warnung_" . $ident),
            $this->GetIDForIdent("WarnungBoe_" . $ident),
            $this->GetIDForIdent("LetzteWarnungTS_" . $ident),
            $this->GetIDForIdent("SchutzAktiv_" . $ident)
        );
    }

    // Dashboard aktualisieren
    $html = WindToolsHelper::erzeugeSchutzDashboard($schutzArray, $this->InstanceID);
    SetValueString($this->GetIDForIdent("SchutzDashboardHTML"), $html);
}

    public function UpdateFromMeteoblue() {
        //$modus = $this->ReadPropertyString("Modus");Relikt aus erster Version
        $logtag = "WindMonitorPro";

        //if ($modus == "fetch") {
            IPS_LogMessage($logtag, "🔁 Modus: Daten von meteoblue abrufen & verarbeiten");
            $this->FetchAndStoreMeteoblueData();         // Holt Daten von meteoblue und speichert sie
            //$this->ReadFromFileAndUpdate();              // Liest gespeicherte Datei und aktualisiert Variablen wird in vorheriger Funktion bereits aufgerufen
        //} elseif ($modus == "readfile") {
            //IPS_LogMessage($logtag, "📂 Modus: Nur lokale Datei verarbeiten");
            //$this->ReadFromFileAndUpdate();              // Nur aus Datei lesen (keine API!)
        //} else {
            //IPS_LogMessage($logtag, "❌ Unbekannter Modus: '$modus'");
        //}
    }


    private function ReloadCSVDatei(): bool {
        IPS_LogMessage("WindMonitorPro", "📁 CSV-Datei wird neu geladen");
        $this->ReadFromFileAndUpdate(); // oder andere Dateioperation
        return true;
    }

    private function ResetSchutzStatus(): void {
        $objekte = IPS_GetChildrenIDs($this->InstanceID);
        foreach ($objekte as $objID) {
            $ident = IPS_GetObject($objID)["ObjectIdent"];
            if (strpos($ident, "Warnung_") === 0) {
                SetValue($objID, false);
            }
        }
        IPS_LogMessage("WindMonitorPro", "🧹 Schutzstatus zurückgesetzt");
    }

    private function WarnungsVariablenLeeren(): void {
        $idHTML = @$this->GetIDForIdent("WindWarnHTML");
        if ($idHTML) {
            SetValue($idHTML, "<div style='color:gray'>Keine aktive Warnung</div>");
        }
        IPS_LogMessage("WindMonitorPro", "🧼 Warnanzeige geleert");
    }

    private float $Grenzwert = 12.0; // Standardwert
    private function SetzeGrenzwert(float $wert): bool {
        $this->Grenzwert = $wert;
        // Rückmeldung schreiben
        $text = "🎚️ Grenzwert gesetzt auf " . number_format($wert, 1) . " m/s am " . date("d.m.Y H:i:s");
        $this->SetValue("LetzteAktion", $text);

        // Optional: Logging
        IPS_LogMessage("WindMonitorPro", "🔧 Grenzwert gesetzt auf $wert");
        return true;
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


        $prefix = "https://my.meteoblue.com/packages/";

        $suffix = $this->ReadPropertyString("PackageSuffix");
        // Prüfung: nur erlaubte Zeichen → Buchstaben, Zahlen, Bindestrich, Unterstrich, Komma
        if (!preg_match('/^[a-z0-9\-_,]+$/i', $suffix)) {
            throw new Exception("❌ Ungültiger Paketname: $suffix");
        }

        $url = $prefix . $suffix
            . "?lat=$lat&lon=$lon&altitude=$alti&apikey=$apiKey&format=json";

        // 📡 URL fest aufbauen
        //$url = "https://my.meteoblue.com/packages/basic-1h_wind-15min,current" .
        //    "?lat=$lat&lon=$lon&altitude=$alti&apikey=$apiKey&format=json";

        // 🌐 Daten abrufen
        $json = @file_get_contents($url);
        if (!$json) {
            IPS_LogMessage($logtag, "❌ meteoblue-Datenabruf fehlgeschlagen");
            return;
        }

        // 💾 Speichern

        $verzeichnis = dirname($file);
        if (!is_dir($verzeichnis)) {
            IPS_LogMessage($logtag, "🌐 Verzeichnis wird angelegt $verzeichnis");
            mkdir($verzeichnis, 0777, true); // Ordner rekursiv erstellen
        }

        $ok = @file_put_contents($file, $json);
        if (!$ok) {
            IPS_LogMessage($logtag, "❌ Speichern nach $file fehlgeschlagen");
            return;
        }
        // ✅ Nach dem Speichern direkt Schutzprüfung starten
        $this->ReadFromFileAndUpdate();


        // String-Variable aktualisieren
        $this->SetValue("FetchJSON", $json);


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

    // 🧾 Schutzstatus-Text setzen
    $richtungText = class_exists("WindToolsHelper") ? WindToolsHelper::gradZuRichtung($grad) : "$grad°";
    $statusText = $schutzAktiv
        ? "⚠️ Schutz aktiv – Windrichtung: $richtungText"
        : "✅ Kein Schutz nötig – Windrichtung: $richtungText";

    SetValueString($this->GetIDForIdent("SchutzStatusText"), $statusText);
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
    function erzeugeSchutzHTML(bool $aktiv, string $zeitstempel, int $nachwirkZeitSek, int $richtung): string {
        $farbe = $aktiv ? "#FF4444" : "#44AA44";
        $text  = $aktiv ? "⚠️ Windwarnung aktiv" : "✔️ Kein Schutz aktiv";

        $gradText = WindToolsHelper::gradZuRichtung($richtung) . " (" . $richtung . "°)";
        $restzeitMin = $nachwirkZeitSek > 0 ? round($nachwirkZeitSek / 60) . " min" : "keine";

        return <<<HTML
        <style>
        .box { padding:10px; border-radius:6px; background-color:$farbe; color:#fff; font-family:Arial; }
        .small { font-size:0.9em; color:#eee; margin-top:6px; }
        </style>
        <div class="box">
        <b>$text</b><br>
        Richtung: $gradText<br>
        Zeitpunkt: $zeitstempel<br>
        Nachwirkzeit: $restzeitMin
        <div class="small">WindMonitorPro</div>
        </div>
        HTML;
}
?>
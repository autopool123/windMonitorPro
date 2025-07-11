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

        // Timer für API-Abruf (meteoblue) -- FetchTimer
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


        $this->RegisterVariableString("SchutzDashboardHTML", "🧯 Schutzobjekt-Dashboard");
        IPS_SetVariableCustomProfile($this->GetIDForIdent("SchutzDashboardHTML"), "~HTMLBox");

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
    $this->RegisterVariableString("LetzteAuswertungDaten", "Letzte Dateiverarbeitung", "~TextBox");
    $this->RegisterVariableString("NachwirkEnde", "Nachwirkzeit endet um", "~TextBox");
    $this->RegisterVariableBoolean("FetchDatenVeraltet", "Daten zu alt", "~Alert");
    $this->RegisterVariableString("LetzteAktion", "Letzte Aktion");



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
        //data_xmin Segment zuweisen wenn existiert und nicht NULL, sonst $block au NULL
        $block = $data['data_xmin'] ?? null;
        //Pruefen ob Time-Block existiert
        if (!$block || !isset($block['time'])) {
            IPS_LogMessage($logtag, "❌ 15 Minutes: Ungültige oder unvollständige JSON-Struktur");
            return;
        }

        $currentData = $data['data_current'] ?? null;
        //Pruefen ob current-data existiert
        if (!$currentData || !isset($currentData['time'])) {
            IPS_LogMessage($logtag, "❌ Current Data: Ungültige oder unvollständige JSON-Struktur");
            return;
        }
        //Timestamp des Meteo-Blue Datensatzes laden, von welcher Uhrzeit stammen die Daten?
        $lokaleZeit = WindToolsHelper::getLokaleModelzeit($data);

        //Lade die von MeteoBlue verwendete Zeitzone aus dem Datenstring 
        $tzAbk = $data["metadata"]["timezone_abbreviation"] ?? 'UTC';//Zeitzone (Kuerzel aus Daten laden)
        $map = WindToolsHelper::getTimezoneMap();//Mapping-Tabelle laden, Kürzel wie "CEST" auf PHP-Zeitzonen wie "Europe/Berlin" abbilden
        //$zone = $map[$tzAbk] ?? 'UTC';//Es wird geprueft, ob im Mapping-Array $map ein Eintrag für das ermittelte Kürzel $tzAbk existiert wenn nicht 'UTC'  
        

        $times = $block['time'];
        //Zeitzone der Daten ermitteln
        $zone = new DateTimeZone($data['metadata']['timezone_abbrevation'] ?? 'UTC');
        //$now = (new DateTime("now", new DateTimeZone($zone)))->format("d.m.Y H:i:s");
        //naechstliegenden 15 Minuten Zeitzyklus (Index) ermitteln... zum auslesen der Arrays 
        $index = WindToolsHelper::getAktuellenZeitIndex($times, $zone);
        if ($index === null) return;

        //TS für das nächste 15 Minuten Intervall
        $timeText = $times[$index];
        SetValueString($this->GetIDForIdent("CurrentTime"), $timeText);
        //TS für die Aktualitaet der MeteoBluedaten 
        SetValueString($this->GetIDForIdent("UTC_ModelRun"), $lokaleZeit);
        //Auslesdatum der Datei speichern, entspricht nicht dem TS: $lokaleZeit der die Zeit der Meteodaten angibt 
        //SetValueString($this->GetIDForIdent("LetzteAuswertungDaten"), $now);

        // Einzelwerte extrahieren Lade 1:1 aus Datei entsprechend Index
        $werte = WindToolsHelper::extrahiereWetterdaten($block, $index);
        $wind = WindToolsHelper::berechneWindObjekt($werte['wind'], WindToolsHelper::$zielHoeheStandard);
        $boe  = WindToolsHelper::berechneWindObjekt($werte['gust'], WindToolsHelper::$zielHoeheStandard);
        $richtung = $werte['dir'];
        $LuftDruck = $werte['pressure'];
        $LuftDichte = $werte['density'];
        $temp = $currentData["temperature"][0] ?? 0;
        $isDay = $currentData["isdaylight"][0] ?? false;
        $uv = $data["data_1h"]["uvindex"][0] ?? 0;

        $updateText = $data["metadata"]["modelrun_updatetime_utc"] ?? "";
        if ($updateText === "" || strlen($updateText) < 10) {
            IPS_LogMessage("WindMonitorPro", "⚠️ Kein gültiger Zeitstempel im metadata gefunden");
            $updateText = gmdate("Y-m-d H:i"); // Fallback in UTC
        }

        // Durchschnittswerte berechnen
        $durchschnitt = WindToolsHelper::berechneDurchschnittswerte($block, $index, 4);
        $SpeedMS = $durchschnitt['avgWind'] ?? 0;
        $SpeedMaxMS = $durchschnitt['maxWind'] ?? 0;
        $GustMaxM = $durchschnitt['maxGust'] ?? 0;
        $DirGrad = $durchschnitt['avgDir'] ?? 0;
        
        //💾 Beschreiben der Status-Variablen 
        SetValueFloat($this->GetIDForIdent("Wind80m"), round($wind * 3.6, 1));
        SetValueFloat($this->GetIDForIdent("Gust80m"), round($boe * 3.6, 1));
        SetValueInteger($this->GetIDForIdent("WindDirection80m"), (int)$richtung);
        SetValueFloat($this->GetIDForIdent("AirPressure"),  round($LuftDruck, 3));
        SetValueFloat($this->GetIDForIdent("AirDensity"), round($LuftDichte, 3));
        SetValue($this->GetIDForIdent("CurrentTemperature"), $temp);
        SetValue($this->GetIDForIdent("IsDaylight"), (bool) $isDay);
        SetValue($this->GetIDForIdent("UVIndex"), $uv);
        //Falls Durchschnittwerte in Statusvariablen gespeichert werden sollen:
        /*
        SetValueFloat($this->GetIDForIdent("SpeedMS"), $SpeedMS);
        SetValueFloat($this->GetIDForIdent("SpeedMaxMS"), $SpeedMaxMS);
        SetValueFloat($this->GetIDForIdent("GustMaxMS"), $GustMaxM);
        SetValueInteger($this->GetIDForIdent("DirGrad"), $DirGrad);
        */

        /*
        //Aus alt uebernommen falls noch gebraucht werden sollte:
        $alpha = $this->ReadPropertyFloat("GelaendeAlpha");
        */

        //Pruefung auf veraltetem Zeitstempel der Daten und setzen Sperrflag
        $utcDatum = substr($updateText, 0, 10); // z. B. "2025-07-04"
        $heuteUTC = gmdate("Y-m-d"); // aktuelles UTC-Datum
        if ($utcDatum !== $heuteUTC) {
            $this->SetValue("SchutzStatusText", "🛑 Meteoblue-Daten stammen nicht vom heutigen UTC-Tag ($utcDatum)");
            IPS_LogMessage("WindMonitorPro", "🛑 Meteoblue-Daten stammen nicht vom heutigen UTC-Tag ($utcDatum)");
            SetValueBoolean($this->GetIDForIdent("WarnungAktiv"), false);
            SetValueBoolean($this->GetIDForIdent("FetchDatenVeraltet"), true);
            $this->SetValue("LetzteAktion", "⏱️ ReadFromFile übersprungen: Daten vom $utcDatum");

            return; // ⛔ Verarbeitung sofort stoppen!
        } else {
            $this->SetValue("SchutzStatusText", "✅ Schutzprüfung erfolgreich durchgeführt mit Daten vom $utcDatum");
            SetValueBoolean($this->GetIDForIdent("FetchDatenVeraltet"), false);
        }





        //$zeit = $data["data_current"]["time"][0] ?? "";




        $schutzArray = json_decode($this->ReadPropertyString("Schutzobjekte"), true);

        // Schritt 1: Alle vorhandenen Schutz-Variablen der Instanz in ein Array schreiben
        // Also ein Array mit allen bereits vorhandenen Schutzvariablen erstellen
        $alleVariablen = [];
        $instanzObjekte = IPS_GetChildrenIDs($this->InstanceID);
        foreach ($instanzObjekte as $objID) {
            $ident = IPS_GetObject($objID)["ObjectIdent"];
            if (strpos($ident, "Warnung_") === 0) {
                $alleVariablen[$ident] = $objID;
            }
            if (strpos($ident, "WarnungBoe_") === 0) {
                $alleVariablen[$ident] = $objID;
            }
            if (strpos($ident, "WarnCount_") === 0) {
                $alleVariablen[$ident] = $objID;
            }
            if (strpos($ident, "WarnCountBoe_") === 0) {
                $alleVariablen[$ident] = $objID;
            }
        }

        // Schritt 2: Mittels Eintraegen im Schutzobjekt prüfen ob alle Variable vorhanden
        // und ggf, falls Neueintrag Variable anlegen
        $genutzteIdents = [];
        //Schreibe alle benoetigten Warnvariablen Idents in das Array $genutzteIdents
        foreach ($schutzArray as $eintrag) {
            $name = $eintrag["Label"] ?? "Unbenannt";
            $ident = "Warnung_" . preg_replace('/\W+/', '_', $name);
            $identBoe = "WarnungBoe_" . preg_replace('/\W+/', '_', $name);
            $identWC = "WarnCount_" . preg_replace('/\W+/', '_', $name);
            $identWCBoe = "WarnCountBoe_" . preg_replace('/\W+/', '_', $name);
            $genutzteIdents[] = $ident;
            $genutzteIdents[] = $identBoe;
            $genutzteIdents[] = $identWC;
            $genutzteIdents[] = $identWCBoe;

            //Pruefen ob Warnung_Name(Warnobjekt-Name) Variable existiert sonst erstellen
            if (!array_key_exists($ident, $alleVariablen)) {
                $vid = $this->RegisterVariableBoolean($ident, "Warnung: " . $name);
                IPS_SetHidden($vid, false); // oder true, je nach Wunsch
                $alleVariablen[$ident] = $vid;
            }

            //Pruefen ob WarnungBoe_Name(Warnobjekt-Name) Variable existiert sonst erstellen
            if (!array_key_exists($identBoe, $alleVariablen)) {
                $vid = $this->RegisterVariableBoolean($identBoe, "WarnungBoe: " . $name);
                IPS_SetHidden($vid, false); // oder true, je nach Wunsch
                $alleVariablen[$identBoe] = $vid;
            }

            //Pruefen ob WarnungCount_Name(Warnobjekt-Name) Variable existiert sonst erstellen
            if (!array_key_exists($identWC, $alleVariablen)) {
                $vid = $this->RegisterVariableInteger($identWC, "WarnCount: " . $name);
                IPS_SetHidden($vid, false); // oder true, je nach Wunsch
                $alleVariablen[$identWC] = $vid;
            }

            //Pruefen ob WarnungCountBoe_Name(Warnobjekt-Name) Variable existiert sonst erstellen
            if (!array_key_exists($identWCBoe, $alleVariablen)) {
                $vid = $this->RegisterVariableInteger($identWCBoe, "WarnCountBoe: " . $name);
                IPS_SetHidden($vid, false); // oder true, je nach Wunsch
                $alleVariablen[$identWCBoe] = $vid;
            }           
        }   

        // Schritt 3: Variablen löschen, die zu entfernten Objekten gehören
        //also Objekt die nicht mehr im Array $genutzteIdents zu finden sind
        foreach ($alleVariablen as $ident => $objID) {
            if (!in_array($ident, $genutzteIdents)) {
            //if (!in_array($ident, $genutzteIdents)&& !in_array($ident, $genutzteBoeIdents)) {
                IPS_LogMessage("WindMonitorPro", "ℹ️ Entferne überflüssige Statusvariable '$ident'");
                IPS_DeleteVariable($objID);
            }
        }
            
        foreach ($schutzArray as $objekt) {
            $name = $objekt["Label"] ?? "Unbenannt";
            $ident = preg_replace('/\W+/', '_', $name);
            $minWind = $objekt["MinWind"] ?? 10.0;
            $minGust = $objekt["MinGust"] ?? 14.0;
            $richtungsliste = $objekt["RichtungsKuerzelListe"] ?? "";


            //Check ob Windrichtung die Warnung fuer Schutzobjekt betrifft
            $kuerzelArray = array_filter(array_map('trim', explode(',', $richtungsliste)));
            $inSektor = WindToolsHelper::richtungPasst($richtung, $kuerzelArray);
            //$inSektor = WindToolsHelper::richtungPasst($richtung, $richtungsliste);
            //Auf Warnstatus checken 
            $warnung = $inSektor && ($wind >= $minWind || $boe >= $minGust);
            $NachwirkZeit = GetValueString($this->GetIDForIdent("NachwirkzeitInfo"));
            $NachwirkZeit = intval($NachwirkZeit);

            WindToolsHelper::berechneSchutzstatusMitNachwirkung(
                $wind,
                $boe,
                $minWind,
                $minGust,
                $NachwirkZeit,
                $this->GetIDForIdent("Warnung_" . $ident),
                $this->GetIDForIdent("WarnungBoe_" . $ident),
                $this->GetIDForIdent("LetzteWarnungTS"),
                $this->GetIDForIdent("WarnungAktiv"),
                $objekt["Label"] ?? "Unbenannt"
            );
        }        

        // Dashboard aktualisieren
        $html = WindToolsHelper::erzeugeSchutzDashboard($schutzArray, $this->InstanceID);
        SetValueString($this->GetIDForIdent("SchutzDashboardHTML"), $html);


           /* 
//Bis hierher bin ich mit der Übernahme der neuen Anregungen aus Ideas gekommen
//Naechste Aenderung waere der Aufruf der Funktion: extrahiereWetterdaten und die Daten-Uebernahme
//Pruefen ob Variablen fuer Durchschnittswerte schon existieren ansonsten anlegen


        // Schritt 1: Alle vorhandenen Schutz-Variablen in Instanz in Array schreiben
        // Also ein Array mit allen bereits vorhandenen Schutzvariablen erstellen
        $alleVariablen = [];
        $instanzObjekte = IPS_GetChildrenIDs($this->InstanceID);
        foreach ($instanzObjekte as $objID) {
            $ident = IPS_GetObject($objID)["ObjectIdent"];
            if (strpos($ident, "Warnung_") === 0) {
                $alleVariablen[$ident] = $objID;
            }
        }


            

            // 🧮 Prüfung wie gewohnt
            $minWind = floatval($eintrag["MinWind"] ?? 0);
            $minGust = floatval($eintrag["MinGust"] ?? 0);
            $kuerzelText = $eintrag["RichtungsKuerzelListe"] ?? "";
            $kuerzelArray = array_map("trim", explode(",", $kuerzelText));

            $richtung = $data["data_xmin"]["winddirection_80m"][0] ?? 0;
            $hoehe = floatval($eintrag["Hoehe"] ?? $this->ReadPropertyFloat("StandardHoehe"));
            //$wind = WindToolsHelper::berechneWindObjekt($data["data_xmin"]["windspeed_80m"][0] ?? 0, $hoehe, 80.0, $this->ReadPropertyFloat("GelaendeAlpha"));
            //$boe  = WindToolsHelper::berechneWindObjekt($data["data_xmin"]["gust"][0] ?? 0, $hoehe, 80.0, $this->ReadPropertyFloat("GelaendeAlpha"));
            
            

            $wind = WindToolsHelper::berechneWindObjekt($data["data_xmin"]["windspeed_80m"][0] ?? 0,$hoehe,80.0,$alpha);
            $boe  = WindToolsHelper::berechneWindObjekt($data["data_xmin"]["gust"][0] ?? 0, $hoehe, 80.0, $alpha);

            $inSektor = false;
            foreach ($kuerzelArray as $kuerzel) {
                if (!WindToolsHelper::isValidKuerzel($kuerzel)) {
                    IPS_LogMessage("WindMonitorPro", "❌ Ungültiges Kürzel '$kuerzel' bei '$name'");
                    continue;
                }
                list($minGrad, $maxGrad) = WindToolsHelper::kuerzelZuWinkelbereich($kuerzel);
                $treffer = ($minGrad < $maxGrad)
                    ? ($richtung >= $minGrad && $richtung <= $maxGrad)
                    : ($richtung >= $minGrad || $richtung <= $maxGrad);
                if ($treffer) {
                    $inSektor = true;
                    break;
                }
            }

            $warnung = $inSektor && ($wind >= $minWind || $boe >= $minGust);
            SetValue($alleVariablen[$ident], $warnung);

            $statusText = $warnung
                ? "⚠️ Schutz aktiv für $name – Wind: $wind m/s, Böe: $boe m/s"
                : "✅ Kein Schutz nötig für $name – Wind: $wind m/s";

            $txtIdent = "Status_" . preg_replace('/\W+/', '_', $name);
            if (!@IPS_VariableExists($this->GetIDForIdent($txtIdent))) {
                $this->RegisterVariableString($txtIdent, "🛡️ Status: $name");
            }
            SetValueString($this->GetIDForIdent($txtIdent), $statusText);
            $schutzStatus[] = [
                'Label'     => $name,
                'Warnung'   => $warnung,
                'Wind'      => round($wind, 2),
                'Boe'       => round($boe, 2),
                'Richtung'  => $richtung,
                'Hoehe'     => $hoehe
            ];


            if ($warnung) {
                //IPS_LogMessage("WindWarnung", "⚠️ '$name' meldet Warnung bei Wind=$wind m/s, Boe=$boe m/s Richtung=$richtung ");
                IPS_LogMessage("WindWarnung", "⚠️ '$name' meldet Warnung bei Wind={$wind} m/s, Boe={$boe} m/s Richtung={$richtung} ");

                

                //Counter für Anzahl Warnungen
                if (@IPS_VariableExists($this->GetIDForIdent($countIdent))) {
                    //$this->RegisterVariableInteger($countIdent, "⚠️ Warnzähler: $name");
                    SetValueInteger($this->GetIDForIdent($countIdent), GetValueInteger($this->GetIDForIdent($countIdent)) + 1);
                }

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
        IPS_LogMessage($logtag, "✅ Datei erfolgreich verarbeitet – Zeitstempel: $updateText");

        //$html = WindToolsHelper::erzeugeSchutzDashboard($schutzArray);
        $html = WindToolsHelper::erzeugeSchutzDashboard($schutzArray, $this->InstanceID);

        SetValueString($this->GetIDForIdent("SchutzDashboardHTML"), $html);     
        
        
        */
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
        $now = (new DateTime("now", new DateTimeZone("Europe/Berlin")))->format("d.m.Y H:i:s");
        SetValueString($this->GetIDForIdent("LetzterFetch"), $now);

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

/*
    public function WMP_FetchMeteoblueOld() {
        $this->FetchAndStoreMeteoblueData(); // holt Daten & speichert JSON

        // Zeitpunkt setzen
        $now = (new DateTime("now", new DateTimeZone("Europe/Berlin")))->format("d.m.Y H:i:s");
        SetValueString($this->GetIDForIdent("LetzterFetch"), $now);
        }

    public function WMP_ReadFromFileOld() {
        $this->ReadFromFileAndUpdate(); // liest JSON & aktualisiert Variablen

        // Zeitpunkt setzen
        $now = (new DateTime("now", new DateTimeZone("Europe/Berlin")))->format("d.m.Y H:i:s");
        SetValueString($this->GetIDForIdent("LetzteAuswertungDaten"), $now);
    }  
*/          

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


//Pruefen und ggf in Klasse aendern ob es erforderlich ist die beiden in der Klasse integrierten Funktionen ausserhalb der Klasse aufzurufen Wurden momentan mit dem Zusatz Wrapper hier eigebaut
//Weil IP-Symcon bei Timer- und Event-Ausführungen nur global sichtbare Funktionen aufrufen kann also ausserhalb der Klasse. Daher ist this->FetchAndStoreMeteoblueData() bei Timern nicht moeglich
//Wrapper Funktion fuer Timer zu FetchAndStoreMeteoblueData
function WMP_FetchMeteoblue($instanceID) {
    $info = IPS_GetInstance($instanceID);
    if ($info['ModuleInfo']['ModuleName'] === "WindMonitorPro") {
        IPS_RunScriptTextEx($instanceID, 'FetchAndStoreMeteoblueData();');
    }
}




//Wrapper Funktion fuer Timer zu ReadFromFileAndUpdate    
//Problematik wie oben beschrieben beachten...
function WMP_ReadFromFile($instanceID) {
    $info = IPS_GetInstance($instanceID);
    if ($info['ModuleInfo']['ModuleName'] === "WindMonitorPro") {
        IPS_RunScriptTextEx($instanceID, 'ReadFromFileAndUpdate();');
    }

}





?>
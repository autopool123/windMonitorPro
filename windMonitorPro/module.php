<?php

require_once(__DIR__ . "/WindToolsHelper.php"); // ⬅️ Dein Helferlein 

class windMonitorPro extends IPSModule {

    public function Create() {
        parent::Create(); // 🧬 Pflicht: Symcon-Basisklasse initialisieren

        // Properties aud form.json registrieren
        $this->RegisterPropertyString("PackageSuffix", "basic-1h_wind-15min,current");
        $this->RegisterPropertyString("APIKey", "");
        $this->RegisterPropertyFloat("Latitude", 49.9842);
        $this->RegisterPropertyFloat("Longitude", 8.2791);
        $this->RegisterPropertyInteger("Altitude", 223);
        $this->RegisterPropertyFloat("Zielhoehe", 8.0);
        $this->RegisterPropertyFloat("Referenzhoehe", 80);
        $this->RegisterPropertyFloat("GelaendeAlpha", 0.14);
        $this->RegisterPropertyBoolean("Aktiv", true);
        $this->RegisterPropertyString("Schutzobjekte", "[]"); // Leere Liste initial

        // Einstellungen für das Abruf-/Auswerteverhalten
        $this->RegisterPropertyString("Dateipfad", "/var/lib/symcon/user/winddata_15min.json");
        $this->RegisterPropertyInteger("FetchIntervall", 120);  // z. B. alle 2h
        $this->RegisterPropertyInteger("MaxDatenAlter", 4);  // z. B. max 4 Std
        $this->RegisterPropertyInteger("ReadIntervall", 15);    // alle 15min
        $this->RegisterPropertyInteger("NachwirkzeitMin", 10);  // Nachwirkzeit in Minuten
        $this->RegisterPropertyInteger("StringVarID", 0); // Optional: ~TextBox-ID

        // Timer registrieren, API-Abruf (meteoblue) FetchTimer
        $this->RegisterTimer("FetchTimer", 0, 'IPS_RequestAction($_IPS[\'TARGET\'], "UpdateMeteoBlue", "");');
        // Timer registrieren Datei-Auswertung
        $this->RegisterTimer("ReadTimer", 0, 'IPS_RequestAction($_IPS[\'TARGET\'], "UpdateWind", "");');

        //Variablen registrieren
        //TimeStamps
        $this->RegisterVariableString("FetchJSON", "Letzter JSON-Download");
        $this->RegisterVariableString("CurrentTime", "Zeitstempel x-min Segment");//Startzeit 15 Minuten Slot
        $this->RegisterVariableString("UTC_ModelRun", "UTC-Zeit der Modellgenerierung");
        //Melde&Infovariablen
        $this->RegisterVariableString("SchutzStatusText", "🔍 Schutzstatus");

        //Symbole vor Schutzvariablennamen stellen 
        $schutzArrayForm = json_decode($this->ReadPropertyString("Schutzobjekte"), true);
        foreach ($schutzArrayForm as $eintrag) {
            $name = $eintrag["Label"] ?? "Unbenannt";
            $ident1 = "Warnung_" . preg_replace('/\W+/', '_', $name);
            $ident2 = "WarnungBoe_" . preg_replace('/\W+/', '_', $name);            
            $txtIdent = "Status_" . preg_replace('/\W+/', '_', $name);
            //Nur wenn die Variable auch existiert 
            if (@IPS_VariableExists($this->GetIDForIdent($ident1))) {
                IPS_SetIcon($this->GetIDForIdent($ident1), "Shield");
            }
            if (@IPS_VariableExists($this->GetIDForIdent($ident2))) {
                IPS_SetIcon($this->GetIDForIdent($ident2), "Shield");
            }            
            if (@IPS_VariableExists($this->GetIDForIdent($txtIdent))) {
                IPS_SetIcon($this->GetIDForIdent($txtIdent), "Alert");
            }
        }
        //HTML-Box-Schutzobjekte-Info registrieren
        $this->RegisterVariableString("SchutzDashboardHTML", "🧯 Schutzobjekt-Dashboard");
        IPS_SetVariableCustomProfile($this->GetIDForIdent("SchutzDashboardHTML"), "~HTMLBox");

    }
    public function ApplyChanges() {
        parent::ApplyChanges();

        // 1. Aktuelle Properties einmal lokal einlesen lesen
                // Timerinterval aus Properties berechnen
        $fetchMin = $this->ReadPropertyInteger("FetchIntervall");//Zyklus zum aktualisieren der Meteofaten und speichern in Datei
        $readMin  = $this->ReadPropertyInteger("ReadIntervall");//Zyklus zum auswerten der Datei um neue 15 Minuten Prognosen zu erstellen
        $maxDatenAlter = $this->ReadPropertyInteger("MaxDatenAlter");

        $readIntervall  = $this->ReadPropertyInteger('ReadIntervall');
        $nachwirkzeitMin   = $this->ReadPropertyInteger('NachwirkzeitMin');

        //if ($nachwirkzeitMin < $readIntervall) {
            // Anpassen mit korrektem Log und evtl. Notifikation für den Benutzer
        //    $this->WritePropertyInteger('NachwirkzeitMin', $readIntervall);
        //    IPS_LogMessage('WindMonitorPro', 'Nachwirkzeit wurde auf ReadIntervall angehoben, um ueberschreiben zu vermeiden.');
        //}

        //Neuladen falls Anpassung erfolgt ist
        //$nachwirkzeitMin = $this->ReadPropertyInteger("NachwirkzeitMin");

        $aktiv = $this->ReadPropertyBoolean("Aktiv");

        // 🔧 Profile erstellen
        // Schutzmodus-Profil erstellen
        if (!IPS_VariableProfileExists("WindPro.Schutzmodus")) {
            IPS_CreateVariableProfile("WindPro.Schutzmodus", VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileIcon("WindPro.Schutzmodus", "Shield");

            IPS_SetVariableProfileAssociation("WindPro.Schutzmodus", 0, "Keine Warnung ausgeben", "", -1);
            IPS_SetVariableProfileAssociation("WindPro.Schutzmodus", 1, "Nur eigene Wetterdaten", "", -1);
            IPS_SetVariableProfileAssociation("WindPro.Schutzmodus", 2, "Nur Prognose", "", -1);
            IPS_SetVariableProfileAssociation("WindPro.Schutzmodus", 3, "Eigene und Prognose", "", -1);
        }

        if (!IPS_VariableProfileExists("WindPro.Speed.1")) {
            IPS_CreateVariableProfile("WindPro.Speed.1", VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileDigits("WindPro.Speed.1", 1);
            IPS_SetVariableProfileText("WindPro.Speed.1", "", " m/s");
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

        if (!IPS_VariableProfileExists("WMP.Rain")) {
            IPS_CreateVariableProfile("WMP.Rain", VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText("WMP.Rain", "", " mm/h");
            IPS_SetVariableProfileDigits("WMP.Rain", 1);
            IPS_SetVariableProfileIcon("WMP.Rain", "Rain");
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


        // 🧾 Variablen registrieren
        $this->RegisterVariableBoolean('FreigabeEigeneWetterstation', 'Eigene Auswertung freigeben', "");
        $this->RegisterVariableString('AktuelleWetterdaten', 'Eigene Wetterdaten (JSON)', '');
        $this->RegisterVariableFloat("Wind80m", "Wind80m[MB_15Min_Date])", "WindPro.Speed.1");
        $this->RegisterVariableFloat("Gust80m", "Boe80m[MB_15Min_Date]", "WindPro.Speed.1");
        $this->RegisterVariableInteger("WindDirection80m", "Windrichtung (80 m)", "WindPro.Direction.Degree");
        $this->RegisterVariableFloat("AirPressure", "Luftdruck", "WMP.AirPressure");
        $this->RegisterVariableFloat("AirDensity", "Luftdichte", "WMP.Density");
        $this->RegisterVariableFloat("CurrentTemperature", "Temperatur", "WMP.Temperature");
        $this->RegisterVariableFloat("Rain", "Regenvorhersage", "WMP.Rain");
        $this->RegisterVariableBoolean("IsDaylight", "Tageslicht", "");
        $this->RegisterVariableInteger("UVIndex", "UV-Index", "");
        $this->RegisterVariableString("WindDirText", "Windrichtung (Text)", "");
        $this->RegisterVariableString("WindDirArrow", "Windrichtung (Symbol)", "");
        $this->RegisterVariableInteger("LetzteWarnungTS", "Letzter Warnzeitpunkt", "");
        $this->RegisterVariableBoolean("WarnungAktiv", "Schutz aktiv", "~Alert");
        $this->RegisterVariableString("SchutzObjekt", "Show Schutzobjekt", "");
        $this->RegisterVariableString("LetzterFetch", "Letzter API-Abruf", "~TextBox");
        $this->RegisterVariableString("LetzteAuswertungDaten", "Letzte Dateiverarbeitung", "~TextBox");
        $this->RegisterVariableString("NachwirkEnde", "Nachwirkzeit endet um", "~TextBox");
        $this->RegisterVariableBoolean("FetchDatenVeraltet", "Daten zu alt", "~Alert");
        $this->RegisterVariableString("LetzteAktion", "Letzte Aktion");

        /*
        //Im Moment nicht realisiert koennte aber noch sinnvoll sein. Arbeiten mit der Systemfunktion MessageSink($TimeStamp, $SenderID, $Message, $Data) (s. unter nach RequestAction)
        //bedeutet, es aendert sich eine Variable die hier in VM_UPDATE registriert wurde woraufhin MessageSink aufgerufen wird und auf ein Ereignis reagiert wird.  
        // Nachricht für automatische Reaktion registrieren
        $vid = @$this->GetIDForIdent("WindSpeed");
        if ($vid > 0) {
            $this->RegisterMessage($vid, VM_UPDATE); // Reagiere auf Wertaenderung
        }
        */


        //Abrufintervalle und Nachwirkzeit
        $this->RegisterVariableString("FetchIntervalInfo", "Abrufintervall (Info)", "~TextBox");
        $this->RegisterVariableString("MaxDatenAlterInfo", "Max Alter MB-Daten", "~TextBox");
        $this->RegisterVariableString("ReadIntervalInfo", "Dateileseintervall (Info)", "~TextBox");
        $this->RegisterVariableString("NachwirkzeitInfo", "Nachwirkzeit (Info)", "~TextBox");

        //Welche Variablen werden entsprechend den Schutzobjekten benoetigt und sind noch nicht existent?    
        $schutzArrayForm = json_decode($this->ReadPropertyString("Schutzobjekte"), true);
        if (!is_array($schutzArrayForm)) {
            IPS_LogMessage("WindMonitorPro", "❌ Schutzobjekte Property enthält kein gültiges JSON.");
            return;
        }
        //Zuweisung der Funktionsrueckgabe alle "gefundenenen Variablen" und "benoetigte Variablen". In der Funktion werden die nicht vorhandenen Variablen angelegt
        [$alleVariablen, $genutzteIdents] = $this->EnsureRequiredVariables($schutzArrayForm,$formSetup = true);
        //Loeschen der vorhandenen aber nicht benoetigten Variablen
        $this->CleanupUnusedVariables($alleVariablen, $genutzteIdents);    



        //Info-Variablen mit aktuellen Werten aktualisieren
        SetValue($this->GetIDForIdent("FetchIntervalInfo"), "$fetchMin Minuten");
        SetValue($this->GetIDForIdent("MaxDatenAlterInfo"), "$maxDatenAlter Stunden");
        SetValue($this->GetIDForIdent("ReadIntervalInfo"), "$readMin Minuten");
        SetValue($this->GetIDForIdent("NachwirkzeitInfo"), "$nachwirkzeitMin Minuten");

        //Klasse der Hilfsfunktionen (WindToolsHelper) versorgen
        WindToolsHelper::setKonfiguration(
            $this->ReadPropertyFloat("GelaendeAlpha"),
            $this->ReadPropertyFloat("Referenzhoehe"),
            $this->ReadPropertyFloat("Zielhoehe"),
            "logarithmisch"
        );


        // Timer.Intervalle setzen, Timer aktivieren, wenn Instanzbutton "aktiv" true ist
        if ($this->ReadPropertyBoolean("Aktiv")) {
            $this->SetTimerInterval("FetchTimer", max(15,$fetchMin) * 60 * 1000);
            $this->SetTimerInterval("ReadTimer", max(15, $readMin) * 60 * 1000);
        } else {
            $this->SetTimerInterval("FetchTimer", 0); // deaktivieren
            $this->SetTimerInterval("ReadTimer",  0); // deaktivieren
        }

            
    }

public function RequestAction($Ident, $Value) {
    // 🔍 Logging für Analysezwecke
    IPS_LogMessage("WindMonitorPro", "⏱️ RequestAction erhalten: $Ident mit Wert=" . print_r($Value, true));
    $this->SetValue("LetzteAktion", "🔀 RequestAction: $Ident Wert=" . print_r($Value, true) . " (" . date("d-m-Y H:i:s") . ")");

    // 🔀 Verteile an Aktion basierend auf Ident
    switch ($Ident) {
        case "UpdateMeteoBlue":
            IPS_LogMessage("WindMonitorPro", "⏱️ RequestAction: $Ident führt jetzt UpdateFromMeteoblue() aus");
            return $this->UpdateFromMeteoblue();

        case "UpdateWind":
            IPS_LogMessage("WindMonitorPro", "⏱️ RequestAction: $Ident führt jetzt UpdateWind() aus");
            return $this->ReadFromFileAndUpdate();

        case "AuswertenEigeneStation":
            IPS_LogMessage("WindMonitorPro", "⏱️ RequestAction: $Ident führt jetzt AuswertenEigeneStationinArbeit() aus");
            return $this->AuswertenEigeneStationinArbeit();

        case 3:
            return $this->PresetCounter(null, 0);

        case "ResetStatus":
            return $this->ResetSchutzStatus();

        case "ShowDashboard":
            // Uebergabe: $Value = Name/Label des Schutzobjekts (String), ggf. leer/null/false = zeige alle
            // Schutzobjekte laden
            $schutzobjekte = json_decode($this->ReadPropertyString("Schutzobjekte"), true);

            // Filter fuer bestimmte Schutzobjekte vorbereiten
            $selectedObjects = [];

            if (!empty($Value)) {
                // $Value kann String oder Array sein
                $labelsToFind = is_array($Value) ? $Value : [$Value];

                foreach ($schutzobjekte as $objekt) {
                    foreach ($labelsToFind as $label) {
                        $cleanLabel = preg_replace('/\W+/', '_', $objekt['Label']);
                        if ($cleanLabel === $label || $objekt['Label'] === $label) {
                            $selectedObjects[] = $objekt;
                            break;
                        }
                    }
                }
            }

            // Falls keine Filterobjekte gefunden oder Wert leer, alle anzeigen
            if (empty($selectedObjects)) {
                $selectedObjects = $schutzobjekte;
            }

            // Dashboard generieren und speichern
            $dashboardHtml = $this->erzeugeSchutzDashboard($selectedObjects, $this->InstanceID);
            SetValue($this->GetIDForIdent("SchutzDashboardHTML"), $dashboardHtml);
            // Gib das HTML aus, z.B. als Rueckgabe, Variablenwert, Webfront, etc.
            //Dashboard aktualisieren
            SetValue($this->GetIDForIdent("SchutzDashboardHTML"), $dashboardHtml);
            return ; 


        default:
            // 🧠 Dynamische Behandlung für WarnModus_<Label>
            if (strpos($Ident, "WarnModus_") === 0) {
                $vid = $this->GetIDForIdent($Ident);
                if ($vid > 0) {
                    SetValueInteger($vid, $Value);

/* Solange die Form mit form.json angelegt wird funktioniert das aktualisieren der Form-Konfiguration nicht.. 
    UpdateFormField() ist ein Werkzeug fuer dynamische Module, aber nur in Kombination mit GetConfigurationForm()                    
                    // 🛠️ Konfiguration aktualisieren
                    $label = substr($Ident, strlen("WarnModus_"));
                    $schutzobjekte = json_decode($this->ReadPropertyString("Schutzobjekte"), true); 
                    foreach ($schutzobjekte as &$eintrag) {
                        $cleanLabel = preg_replace('/\W+/', '_', $eintrag["Label"]);
                        if ($cleanLabel === $label) {
                            $eintrag["Warnmodus"] = $Value;
                            IPS_LogMessage("WindMonitorPro", "🔄 Konfiguration aktualisiert: {$eintrag["Label"]} => Warnmodus = $Value");
                            break;
                        }
                    }

                    // 📝 Konfiguration zurückschreiben
                    $this->UpdateFormField("Schutzobjekte", "value", json_encode($schutzobjekte));
*/

                    return;
                }
            }

            // 🚫 Unbekannter Ident
            throw new Exception("⚠️ Ungültiger Aktion-Identifier: " . $Ident);
    }
}

/*
    public function RequestAction($Ident, $Value) {
        // 🔍 Logging für Analysezwecke
        IPS_LogMessage("WindMonitorPro", "⏱️ RequestAction erhalten: $Ident mit Wert=" . print_r($Value, true));
        $this->SetValue("LetzteAktion","🔀 RequestAction: $Ident Wert=" . print_r($Value, true) . " (" . date("d-m-Y H:i:s") . ")"    );

        // 🔀 Verteile an Aktion basierend auf Ident
        switch ($Ident) {
            case "UpdateMeteoBlue":
                IPS_LogMessage("WindMonitorPro", "⏱️ RequestAction erhalten: $Ident fuehrt jetzt UpdateFromMeteoblue() aus" );
                return $this->UpdateFromMeteoblue();

            case "UpdateWind":
                IPS_LogMessage("WindMonitorPro", "⏱️ RequestAction erhalten: $Ident fuehrt jetzt UpdateWin() aus" );
                return $this->ReadFromFileAndUpdate();

            case "AuswertenEigeneStation":
                IPS_LogMessage('WindMonitorPro', "RequestAction erhalten: $Ident fuehrt jetzt AuswertenEigeneStation() aus");
                return $this->AuswertenEigeneStationinArbeit();

            case "ResetCounter"://Aufruf ueber RequestAction setzt alle Zaehler auf 0 da countValue fix auf 0 gesetzt wird
                return $this->PresetCounter(null,0);

            case "ResetStatus":
                return $this->ResetSchutzStatus();

            default:
                throw new Exception("⚠️ Ungültiger Aktion-Identifier: " . $Ident);
        }
    }
*/

    //Funktion MessageSink wird derzeit nicht verwendet. Ist gedacht um auf Aenderungen von Statusvariablen zu reagieren welche aber vorher registriert werden müssen
    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
        {
            if ($Message == VM_UPDATE) {
                $newValue = $Data[0];
                $oldValue = $Data[2];

                IPS_LogMessage("WindMonitor", "Windgeschwindigkeit geaendert: Alt=$oldValue, Neu=$newValue");

                // Beispiel: Wenn Wind > 50 km/h, Alarm auslösen
            if ($SenderID == $this->GetIDForIdent("WindSpeed")) {
                IPS_LogMessage("WindMonitor", "🌬️ Windgeschwindigkeit geaendert: $oldValue → $newValue");
            } elseif ($SenderID == $this->GetIDForIdent("WindDirection")) {
                IPS_LogMessage("WindMonitor", "🧭 Windrichtung geaendert: $oldValue $newValue");
            }
        }   
    } 
    public function UpdateFromMeteoblue() {
        //$modus = $this->ReadPropertyString("Modus");Relikt aus erster Version
        $logtag = "WMP_UpdateFromMeteoblue";

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

        //Datensegment 15 Minuten-Werte (data_xmin) zuweisen wenn existiert und nicht NULL, sonst $block auf NULL
        $block = $data['data_xmin'] ?? null;
        //Pruefen ob Time-Block existiert
        if (!$block || !isset($block['time'])) {
            IPS_LogMessage($logtag, "❌ 15 Minutes (data_xmin): Ungültige oder unvollständige JSON-Struktur");
            return;
        }

        //Datensegment 1-Std-Werte
        $blockStd = $data['data_1h'] ?? null;
                //Pruefen ob current-data existiert
        if (!$blockStd || !isset($blockStd['time'])) {
            IPS_LogMessage($logtag, "❌ Stundenwerte(data_1h): Ungültige oder unvollständige JSON-Struktur");
            return;
        }

        /*
        //Datensegment data-current
        $currentData = $data['data_current'] ?? null;
        //Pruefen ob current-data existiert
        if (!$currentData || !isset($currentData['time'])) {
            IPS_LogMessage($logtag, "❌ Current Data: Ungültige oder unvollständige JSON-Struktur");
            return;
        }
        */

        //Lade die von MeteoBlue verwendete Zeitzonen Abkuerzung aus dem Datenstring oder setze bei Fehler auf UTC
        $tzAbk = $data["metadata"]["timezone_abbrevation"] ?? 'UTC';//Zeitzone (Kuerzel aus Daten laden)
        //Zum Kuerzel gehoerige Zeitzonenbezeichung ermitteln
        $map = WindToolsHelper::getTimezoneMap();//Mapping-Tabelle laden, Kuerzel wie "CEST" auf PHP-Zeitzonen-Namen wie "Europe/Berlin" abbilden
        $zoneAbk = $map[$tzAbk] ?? 'UTC';//Es wird geprueft, ob im Mapping-Array $map ein Eintrag für das ermittelte Kürzel $tzAbk existiert wenn nicht 'UTC' 
        //DateTimeZone-Objekt erzeugen
        $zone = new DateTimeZone($zoneAbk); // Erzeugt eine gültige PHP-Zeitzone
        //Timestamp des Meteo-Blue Datensatzes laden und in lokale Zeit wandeln, von welcher Uhrzeit stammen die Daten?
        //metadata":{"modelrun_updatetime_utc...
        $ModelZeit = WindToolsHelper::getLokaleModelzeit($data,$zone);
        $ModelZeitEU = WindToolsHelper::formatToEuropeanDate($ModelZeit);
        SetValue($this->GetIDForIdent("UTC_ModelRun"), $ModelZeitEU);
        //Pruefung auf veraltetem Zeitstempel der Daten und setzen Sperrflag
        $datenZeit = DateTime::createFromFormat('Y-m-d H:i', $ModelZeit, new DateTimeZone('UTC'));
        $jetztUTC = new DateTime('now', new DateTimeZone('UTC'));
        $diff = $jetztUTC->getTimestamp() - $datenZeit->getTimestamp();
        
        $maxDatenAlterSekunden = ($this->ReadPropertyInteger("MaxDatenAlter")) * 3600;
        if ($diff > $maxDatenAlterSekunden) {
            $this->SetValue("SchutzStatusText", "🛑 Meteoblue-Daten älter als 4 Stunden (UTC: $ModelZeit)");
            IPS_LogMessage("WindMonitorPro", "🛑 Meteoblue-Daten älter als 4 Stunden (UTC: $ModelZeit)");
            //SetValueBoolean($this->GetIDForIdent("WarnungAktiv"), false);
            SetValueBoolean($this->GetIDForIdent("FetchDatenVeraltet"), true);
            $this->SetValue("LetzteAktion", "⏱️ ReadFromFile übersprungen: Daten von $ModelZeit");
        return;
        } else {
            $this->SetValue("SchutzStatusText", "✅ MeteoBluedaten erfolgreich eingelesen und gespeichert mit MB-Timestamp: $ModelZeit");
            SetValueBoolean($this->GetIDForIdent("FetchDatenVeraltet"), false);
        }  

        //Timestamp Auswertedatum, letztes Dateiupdate der Datei speichern, entspricht nicht dem TS: $ModelZeit der die Zeit der Meteodaten angibt 
        $now = (new DateTime("now", $zone))->format("d.m.Y H:i:s");
        SetValue($this->GetIDForIdent("LetzteAuswertungDaten"), $now);
        //15 Minuten Timeblock laden in $times 
        $times = $block['time'];
        //1h Timeblock laden in $timesStd 
        $timesStd = $blockStd['time'];
        //naechstliegenden 15 Minuten Zeitzyklus (Index) ermitteln... zum auslesen der Werte-Arrays 
        $index = WindToolsHelper::getAktuellenZeitIndex($times, $zone);
        if ($index === null) {
            IPS_LogMessage($logtag, "❌ Konnte keinen X-Min-Index ermitteln");
            return;
        }
        //naechstliegenden 1Std Zeitzyklus (IndexStd) ermitteln... zum auslesen der Werte-Arrays
        $indexStd = WindToolsHelper::getAktuellenZeitIndex($timesStd, $zone);
        if ($indexStd === null) {
            IPS_LogMessage($logtag, "❌ Konnte keinen 1Std-Index ermitteln");
            return;
        }
        //TS fuer das naechste 15 Minuten Intervall
        $timeSlot15Min = $times[$index];
        $ModelZeitEU = WindToolsHelper::formatToEuropeanDate($timeSlot15Min);        
        SetValue($this->GetIDForIdent("CurrentTime"), $ModelZeitEU);

        // Einzelwerte extrahieren Lade 1:1 aus Datei entsprechend Index
        $werte = WindToolsHelper::extrahiereWetterdaten($block, $index);
        //$wind = WindToolsHelper::berechneWindObjekt($werte['wind'], WindToolsHelper::$zielHoeheStandard);
        //$boe  = WindToolsHelper::berechneWindObjekt($werte['gust'], WindToolsHelper::$zielHoeheStandard);
        $wind = $werte['wind'];
        $boe  = $werte['gust'];
        $richtung = $werte['dir'];
        $LuftDruck = $werte['pressure'];
        $LuftDichte = $werte['density'];

        // Einzelwerte (1Std-Werte) aus Datei entsprechend Stunden Index
        $temp = $blockStd["temperature"][$indexStd] ?? 0;
        $isDay = $blockStd["isdaylight"][$indexStd] ?? false;
        $uv = $blockStd["uvindex"][$indexStd] ?? 0;
        $rain = $blockStd["precipitation"][$indexStd] ?? 0;  //Regen Stundenvorhersage in mm/h



// Hier gehts weiter----- Statusvariablen schreiben 



        
        //💾 Beschreiben der Status-Variablen 
        $windInObjHoehe = WindToolsHelper::windUmrechnungSmart($wind, WindToolsHelper::$referenzhoehe, WindToolsHelper::$zielHoeheStandard, WindToolsHelper::$gelaendeAlpha);
        $boeInObjHoehe = WindToolsHelper::windUmrechnungSmart($boe, WindToolsHelper::$referenzhoehe, WindToolsHelper::$zielHoeheStandard, WindToolsHelper::$gelaendeAlpha);
        SetValueFloat($this->GetIDForIdent("Wind80m"), round($windInObjHoehe, 2));
        SetValueFloat($this->GetIDForIdent("Gust80m"), round($boeInObjHoehe, 2));
        SetValueInteger($this->GetIDForIdent("WindDirection80m"), (int)$richtung);
        SetValue($this->GetIDForIdent("WindDirText"),WindToolsHelper::gradZuRichtung($richtung));
        SetValue($this->GetIDForIdent("WindDirArrow"),WindToolsHelper::gradZuPfeil($richtung));
        SetValueFloat($this->GetIDForIdent("AirPressure"),  round($LuftDruck, 3));
        SetValueFloat($this->GetIDForIdent("AirDensity"), round($LuftDichte, 3));
        SetValue($this->GetIDForIdent("CurrentTemperature"), $temp);
        SetValue($this->GetIDForIdent("IsDaylight"), (bool) $isDay);
        SetValue($this->GetIDForIdent("UVIndex"), $uv);
        SetValue($this->GetIDForIdent("Rain"), $rain);

        IPS_LogMessage($logtag, "🔍 Std-Werte: UV: $uv, Temperature: $temp, tag: $isDay, Regen: $rain");
        //Falls Durchschnittwerte in Statusvariablen gespeichert werden sollen:
        /*
        $durchschnitt = WindToolsHelper::berechneDurchschnittswerte($block, $index, 4);
        $SpeedMS = $durchschnitt['avgWind'] ?? 0;
        $SpeedMaxMS = $durchschnitt['maxWind'] ?? 0;
        $GustMaxM = $durchschnitt['maxGust'] ?? 0;
        $DirGrad = $durchschnitt['avgDir'] ?? 0;
        SetValueFloat($this->GetIDForIdent("SpeedMS"), $SpeedMS);
        SetValueFloat($this->GetIDForIdent("SpeedMaxMS"), $SpeedMaxMS);
        SetValueFloat($this->GetIDForIdent("GustMaxMS"), $GustMaxM);
        SetValueInteger($this->GetIDForIdent("DirGrad"), $DirGrad);
        */

        //Statusvariablen anlegen oder loeschen entsprechend Schutzarray
        $schutzArrayForm = json_decode($this->ReadPropertyString("Schutzobjekte"), true);
        if (!is_array($schutzArrayForm)) {
            IPS_LogMessage("WindMonitorPro", "❌ Schutzobjekte Property enthält kein gültiges JSON.");
            return;
        }
        //Zuweisung der Funktionsrueckgabe alle gefundenenen Variablen und benoetigte Variablen. In der Funktion werden die nicht vorhandenen Variablen angelegt
        [$alleVariablen, $genutzteIdents] = $this->EnsureRequiredVariables($schutzArrayForm,$formSetup = false);
        //Loeschen der vorhandenen aber nicht benoetigten Variablen
        $this->CleanupUnusedVariables($alleVariablen, $genutzteIdents);
        
        $SammelWarnung = false;
        foreach ($schutzArrayForm as $objekt) {
            $name = $objekt["Label"] ?? "Unbenannt";
            $modus =$objekt["Warnmodus"]; 
            $ident = preg_replace('/\W+/', '_', $name);
            $minWind = $objekt["MinWind"] ?? 10.0;
            $minGust = $objekt["MinGust"] ?? 14.0;
            //Bilde ein array aus den in der form.json eingegebenen gefärdenden Windrichtungen
            $richtungsliste = $objekt["RichtungsKuerzelListe"] ?? "";
            $kuerzelArray = array_filter(array_map('trim', explode(',', $richtungsliste)));
            $hoehe = $objekt["Hoehe"] ?? WindToolsHelper::$zielHoeheStandard;
            $windInObjHoehe = WindToolsHelper::windUmrechnungSmart($wind, WindToolsHelper::$referenzhoehe, $hoehe, WindToolsHelper::$gelaendeAlpha);
            $boeInObjHoehe = WindToolsHelper::windUmrechnungSmart($boe, WindToolsHelper::$referenzhoehe, $hoehe, WindToolsHelper::$gelaendeAlpha);
            $idstatusStr = $this->GetIDForIdent("Status_" . $ident);
            $idwarnModus = $this->GetIDForIdent("WarnModus_" . $ident);

            //Check ob Windrichtung die Warnung fuer Schutzobjekt betrifft
            //$inSektor = WindToolsHelper::richtungPasst($richtung, $kuerzelArray);//wird jetzt in berechneSchutzstatusMitNachwirkung behandelt
            //Auf Warnstatus checken 

            $NachwirkZeitString = GetValueString($this->GetIDForIdent("NachwirkzeitInfo"));
            $NachwirkZeit = (preg_match('/\d+/', $NachwirkZeitString, $match)) ? intval($match[0]) : 10;
            $warnsource = "MeteoBlue-Daten";
            $NewStatusArray = WindToolsHelper::berechneSchutzstatusMitNachwirkung(
                $objekt,
                $idwarnModus,
                $warnsource,
                $windInObjHoehe,
                $boeInObjHoehe,
                $minWind,
                $minGust,
                $richtung,
                $kuerzelArray,
                $NachwirkZeit,
                $this->GetIDForIdent("Status_" . $ident), 
                $this->GetIDForIdent("Warnung_" . $ident),
                $this->GetIDForIdent("WarnungBoe_" . $ident),
                
            );
            


            // Vorschau berechnen
            $BoeGefahrVorschau = WindToolsHelper::ermittleWindAufkommen($block, $minGust, $hoehe);
            //Array um BoeVorschau erweitern
            $NewStatusArray['boeVorschau'] = $BoeGefahrVorschau;

            //pruefe ob gueltige Json-Eintraege in Status-Variable vorhanden, sonst Preset und später beschreiben...
            $statusJson = GetValueString($idstatusStr);
            $StatusCheckValuesJson = json_decode($statusJson, true);
            if ($statusJson === '' || !is_array($StatusCheckValuesJson)) {
                // Fehlerbehandlung: JSON ist ungültig oder ist kein Array
                // Preset array Statusdaten
                $boeVorschauPreset = "{}";
                //$StatusCheckValuesJson = $this->getStatusPresetArray($objekt, $windInObjHoehe $windInObjHoehe,$kuerzelArray, $boeVorschauPreset$BoeGefahrVorschau);
                $StatusCheckValuesJson = $this->getStatusPresetArray($objekt,$windInObjHoehe,$boeInObjHoehe,$kuerzelArray,$boeVorschauPreset);
                SetValue($idstatusStr, json_encode($StatusCheckValuesJson));
            }

            // Status-JSON aktualisieren und auf Statusvariable schreiben mit eventuellem Fallback
            WindToolsHelper::UpdateStatusJsonFields($idstatusStr, $NewStatusArray);
            
            //Statusvariablen aktualisieren
            // IDs der Zählervariablen holen
            $idWarnCount = $this->GetIDForIdent("WarnCount_" . $ident);
            $idWarnCountBoe = $this->GetIDForIdent("WarnCountBoe_" . $ident);

            // Werte aus JsonStatus holen, mit Default 0 falls nicht gesetzt
            $countWind = $NewStatusArray['countWind'] ?? 0;
            $countGust = $NewStatusArray['countGust'] ?? 0;
            $WarnWindNeu = $NewStatusArray['warnWind'] ?? false;
            $WarnGustNeu = $NewStatusArray['warnGust'] ?? false;

            // Werte schreiben, wenn Variablen existieren
            if ($idWarnCount !== false) {
                SetValue($idWarnCount, $countWind);
            }
            if ($idWarnCountBoe !== false) {
                SetValue($idWarnCountBoe, $countGust);
            }
 
            //Sammelwarnung bei Wind oder Boe-Warnung
            if($WarnWindNeu || $WarnGustNeu){
                $SammelWarnung = true;
            }

        } 
        $idSammelWarn = $this->GetIDForIdent("WarnungAktiv");
        if ($idSammelWarn !== false) {
            SetValueBoolean($idSammelWarn, $SammelWarnung);
        }
        //SetValueBoolean($this->GetIDForIdent("WarnungAktiv"), $SammelWarnung);

        // Dashboard aktualisieren
        //$html = $this->erzeugeSchutzDashboard($schutzArrayForm, $this->InstanceID);
        //SetValue($this->GetIDForIdent("SchutzDashboardHTML"), $html);

        //Rueckmeldung
        IPS_LogMessage("WindMonitorPro", "📁 Datei-Daten gelesen");
    }


    public function AuswertenEigeneStationinArbeit(): void
    {
        $logtag = "WindMonitorPro";
        $vid = @IPS_GetObjectIDByIdent("AktuelleWetterdaten", $this->InstanceID);
        if ($vid === false || !IPS_VariableExists($vid)) {
            IPS_LogMessage($logtag, "❌ NetatmoJSON-Variable nicht gefunden");
            return;
        }
        $json = GetValueString($vid);
        $werte = WindToolsHelper::getNetatmoCurrentArray($this->InstanceID, $json);

        $source = $werte["source"] ?? "unbekannt";
        $zeit = $werte["time"] ?? "unbekannt";
        $wind = $werte["windspeed"] ?? 0.0;
        $gust = $werte["gust"] ?? 0.0;
        $richtung  = $werte["winddirection"] ?? 0;
        $temp = $werte["temperature"] ?? 0.0; 
        $messniveau = $werte["hoehestation"] ?? 6.0; 

        $maximalSekunden = 30 * 60; 
        $maxWerteAlterSekErreicht = WindToolsHelper::istMaximalzeitErreicht($zeit, $maximalSekunden);

        if ($maxWerteAlterSekErreicht) {
            $this->SetValue("SchutzStatusText", "🛑 eigene Wetterdaten-Daten älter als 30 Minuten (Zeitstempel: $zeit)");
            IPS_LogMessage($logtag, "🛑 eigene Wetterdaten-Daten älter als 30 Minuten (Zeitstempel: $zeit)");
            SetValueBoolean($this->GetIDForIdent("FetchDatenVeraltet"), true);
            $this->SetValue("LetzteAktion", "⏱️ Auswertung eigene Daten übersprungen: Daten von $zeit");
            return;
        } else {
            $this->SetValue("SchutzStatusText", "✅ Eigene Wetterdaten erfolgreich eingelesen mit Timestamp: $zeit");
            SetValueBoolean($this->GetIDForIdent("FetchDatenVeraltet"), false);
        }

        // Berechnung auf Referenzhöhe
        $windInObjHoehe = WindToolsHelper::windUmrechnungSmart($wind, $messniveau, WindToolsHelper::$zielHoeheStandard, WindToolsHelper::$gelaendeAlpha);
        $boeInObjHoehe  = WindToolsHelper::windUmrechnungSmart($gust, $messniveau, WindToolsHelper::$zielHoeheStandard, WindToolsHelper::$gelaendeAlpha);

        $schutzArrayForm = json_decode($this->ReadPropertyString("Schutzobjekte"), true);
        if (!is_array($schutzArrayForm)) {
            IPS_LogMessage($logtag, "❌ Schutzobjekte Property enthält kein gültiges JSON.");
            return;
        }

        $SammelWarnung = false;
        foreach ($schutzArrayForm as $objekt) {
            $name = $objekt["Label"] ?? "Unbenannt";
            $modus =$objekt["Warnmodus"]; 
            $ident = preg_replace('/\W+/', '_', $name);
            $minWind = $objekt["MinWind"] ?? 10.0;
            $minGust = $objekt["MinGust"] ?? 14.0;
            $richtungsliste = $objekt["RichtungsKuerzelListe"] ?? "";
            $kuerzelArray = array_filter(array_map('trim', explode(',', $richtungsliste)));
            $hoehe = $objekt["Hoehe"] ?? WindToolsHelper::$zielHoeheStandard;
            $windInObjHoehe = WindToolsHelper::windUmrechnungSmart($wind, $messniveau, $hoehe, WindToolsHelper::$gelaendeAlpha);
            $boeInObjHoehe = WindToolsHelper::windUmrechnungSmart($gust, $messniveau, $hoehe, WindToolsHelper::$gelaendeAlpha);
            $idstatusStr = $this->GetIDForIdent("Status_" . $ident);
            $idwarnModus = $this->GetIDForIdent("WarnModus_" . $ident);
            if ($idstatusStr === false) {
                IPS_LogMessage($logtag, "Statusvariable für $ident nicht gefunden.");
                continue;
            }

            $NachwirkZeitString = GetValueString($this->GetIDForIdent("NachwirkzeitInfo"));
            $NachwirkZeit = (preg_match('/\d+/', $NachwirkZeitString, $match)) ? intval($match[0]) : $this->ReadPropertyInteger('ReadIntervall');
            $warnsource = "Eigene Wetterstation";
            $NewStatusArray = WindToolsHelper::berechneSchutzstatusMitNachwirkung(
                $objekt,
                $idwarnModus,
                $warnsource,
                $windInObjHoehe,
                $boeInObjHoehe,
                $minWind,
                $minGust,
                $richtung,
                $kuerzelArray,
                $NachwirkZeit,
                $idstatusStr, 
                $this->GetIDForIdent("Warnung_" . $ident),
                $this->GetIDForIdent("WarnungBoe_" . $ident),
            );

            $statusJson = GetValueString($idstatusStr);
            $StatusCheckValuesJson = json_decode($statusJson, true);

            if ($statusJson === '' || !is_array($StatusCheckValuesJson)) {
                $boeVorschauPreset = "{}";
                //$StatusCheckValuesJson = $this->getStatusPresetArray($name, $modus,$hoehe, 0, 0, 0, 0, $kuerzelArray, $boeVorschauPreset);
                $StatusCheckValuesJson = $this->getStatusPresetArray($objekt,$windInObjHoehe,$boeInObjHoehe,$kuerzelArray,$boeVorschauPreset);
                SetValue($idstatusStr, json_encode($StatusCheckValuesJson));
            }

            WindToolsHelper::UpdateStatusJsonFields($idstatusStr, $NewStatusArray);

            $idWarnCount = $this->GetIDForIdent("WarnCount_" . $ident);
            $idWarnCountBoe = $this->GetIDForIdent("WarnCountBoe_" . $ident);

            $countWind = $NewStatusArray['countWind'] ?? 0;
            $countGust = $NewStatusArray['countGust'] ?? 0;
            $WarnWindNeu = $NewStatusArray['warnWind'] ?? false;
            $WarnGustNeu = $NewStatusArray['warnGust'] ?? false;

            if ($idWarnCount !== false) {
                SetValue($idWarnCount, $countWind);
            }
            if ($idWarnCountBoe !== false) {
                SetValue($idWarnCountBoe, $countGust);
            }

            if ($WarnWindNeu || $WarnGustNeu) {
                $SammelWarnung = true;
            }
        }

        $idSammelWarn = $this->GetIDForIdent("WarnungAktiv");
        if ($idSammelWarn !== false) {
            SetValueBoolean($idSammelWarn, $SammelWarnung);
        }

        //$html = $this->erzeugeSchutzDashboard($schutzArrayForm, $this->InstanceID);
        //SetValue($this->GetIDForIdent("SchutzDashboardHTML"), $html);

        IPS_LogMessage($logtag, "Eigene Wetterdaten ausgewertet");
    }


    public function PresetCounter(?string $objekt, int $countValue = 0)
    {
        $logtag = 'WMP_PresetCounter';
        $schutzArrayForm = json_decode($this->ReadPropertyString("Schutzobjekte"), true);

        if (!is_array($schutzArrayForm)) {
            IPS_LogMessage($logtag, "❌ Schutzobjekte Property enthält kein gültiges JSON.");
            return;
        }

        $objektGefunden = false;
        foreach ($schutzArrayForm as $eintrag) {
            // Prüfung, ob "Label" existiert und ist nicht leer
            if (!isset($eintrag["Label"]) || trim($eintrag["Label"]) === "") {
                IPS_LogMessage($logtag, "Warnung: Schutzobjekt ohne Label übersprungen.");
                continue;
            }
            $name = $eintrag["Label"];

            // Prüfe, ob selektiert werden soll (wenn $objekt leer/null, immer true)
            if (is_string($objekt) && $objekt !== '' && $name !== $objekt) {
                continue; // <--- Nicht das gewünschte, überspringen
            }

            $objektGefunden = true; // Mindestens ein Objekt selektiert

            $ident1     = "WarnCount_" . preg_replace('/\W+/', '_', $name);
            $ident2     = "WarnCountBoe_" . preg_replace('/\W+/', '_', $name);
            $statusIdent = "Status_" . preg_replace('/\W+/', '_', $name);

            // Wind-Zähler auf $countValue setzen
            $varID1 = @$this->GetIDForIdent($ident1);
            if ($varID1 !== false && IPS_VariableExists($varID1)) {
                SetValueInteger($varID1, $countValue);

                $statusID = @$this->GetIDForIdent($statusIdent);
                if ($statusID !== false && IPS_VariableExists($statusID)) {
                    $NewStatusArray = ['countWind' => $countValue];
                    WindToolsHelper::UpdateStatusJsonFields($statusID, $NewStatusArray);
                }
            }

            // Böen-Zähler auf $countValue setzen
            $varID2 = @$this->GetIDForIdent($ident2);
            if ($varID2 !== false && IPS_VariableExists($varID2)) {
                SetValueInteger($varID2, $countValue);

                $statusID = @$this->GetIDForIdent($statusIdent);
                if ($statusID !== false && IPS_VariableExists($statusID)) {
                    $NewStatusArray = ['countGust' => $countValue];
                    WindToolsHelper::UpdateStatusJsonFields($statusID, $NewStatusArray);
                }
            }

            // Wenn nur ein bestimmtes Objekt bearbeitet werden soll hier abbrechen da nun erledigt
            if (is_string($objekt) && $objekt !== '') break;
        }

        if (is_string($objekt) && $objekt !== '' && !$objektGefunden) {
            IPS_LogMessage($logtag, "Schutzobjekt '$objekt' wurde nicht gefunden!");
        } else {
            $msg = (is_string($objekt) && $objekt !== '') ? $objekt : 'alle';
            IPS_LogMessage($logtag, "Counter: $msg auf $countValue gesetzt");
        }
    }



    private function ResetSchutzStatus(): void {
        $objekte = IPS_GetChildrenIDs($this->InstanceID);
        foreach ($objekte as $objID) {
            $ident = IPS_GetObject($objID)["ObjectIdent"];
            if (strpos($ident, "Warnung_") === 0) {
                SetValue($objID, false);
            }
            if (strpos($ident, "WarnungBoe_") === 0) {
                SetValue($objID, false);
            }            
        }
        IPS_LogMessage("WindMonitorPro", "🧹 Schutzstatus zurückgesetzt");
    }
    private function CreateActionScriptForIdent(string $ident, int $variableID): void
    {
        $scriptIdent = "Action_Warnmodus";

        // Prüfen, ob zentrales Skript bereits existiert
        $scriptID = @IPS_GetObjectIDByIdent($scriptIdent, $this->InstanceID);
        if ($scriptID === false) {
            $scriptID = IPS_CreateScript(0); // PHP-Skript
            IPS_SetName($scriptID, "WarnmodusAktion");
            IPS_SetIdent($scriptID, $scriptIdent);
            IPS_SetParent($scriptID, $this->InstanceID);

            // Zentrales Skript für alle Warnmodus-Variablen
            $scriptCode = <<<EOD
    <?php
    \$variableID = \$_IPS['VARIABLE'];
    \$value = \$_IPS['VALUE'];
    \$ident = IPS_GetObject(\$variableID)['ObjectIdent'];
    \$instanceID = IPS_GetParent(\$variableID);
    IPS_RequestAction(\$instanceID, \$ident, \$value);
    IPS_LogMessage("Warnmodus", "[\$ident] wurde auf [\$value] gesetzt");
    EOD;

            IPS_SetScriptContent($scriptID, $scriptCode);
        }

        // Aktionsskript zuweisen
        IPS_SetVariableCustomAction($variableID, $scriptID);
    }


/*
    private function CreateActionScriptForIdent(string $ident, int $variableID): void
    {
        $scriptIdent = "Action_" . $ident;

        // Prüfen, ob Skript bereits existiert
        $scriptID = @IPS_GetObjectIDByIdent($scriptIdent, $this->InstanceID);
        if ($scriptID === false) {
            $scriptID = IPS_CreateScript(0); // PHP-Skript
            IPS_SetName($scriptID, $scriptIdent);
            IPS_SetIdent($scriptID, $scriptIdent);
            IPS_SetParent($scriptID, $this->InstanceID);

            $scriptCode = "<?php\nIPS_RequestAction(" . $this->InstanceID . ", \"" . $ident . "\", \$_IPS['VALUE']);";

            IPS_SetScriptContent($scriptID, $scriptCode);
        }

        // Aktionsskript zuweisen
        IPS_SetVariableCustomAction($variableID, $scriptID);
    }
*/
    private function EnsureRequiredVariables(array $schutzArrayForm,$formSetup = false): array
{
    $genutzteIdents = [];
    $alleVariablen = [];

    // Bestehende Schutzvariablen laden
    $instanzObjekte = IPS_GetChildrenIDs($this->InstanceID);
    foreach ($instanzObjekte as $objID) {
        $ident = IPS_GetObject($objID)["ObjectIdent"];
        if (strpos($ident, "Warnung_") === 0 ||
            strpos($ident, "WarnungBoe_") === 0 ||
            strpos($ident, "WarnCount_") === 0 ||
            strpos($ident, "WarnCountBoe_") === 0 ||
            strpos($ident, "WarnModus_") === 0 ||
            strpos($ident, "Status_") === 0) {
            $alleVariablen[$ident] = $objID;
        }
    }

    foreach ($schutzArrayForm as $eintrag) {
        $name = $eintrag["Label"] ?? "Unbenannt";
        $cleanName = preg_replace('/\W+/', '_', $name);

        $idents = [
            "Warnung_" . $cleanName,
            "WarnungBoe_" . $cleanName,
            "WarnCount_" . $cleanName,
            "WarnCountBoe_" . $cleanName,
            "WarnModus_" . $cleanName,
            "Status_" . $cleanName,
        ];

        // Alle gesammelten Idents merken
        foreach ($idents as $ident) {
            $genutzteIdents[] = $ident;
        }

        // Warnung
        if (!array_key_exists($idents[0], $alleVariablen)) {
            $vid = $this->RegisterVariableBoolean($idents[0], "Warnung: " . $name, "~Alert");
            IPS_SetHidden($vid, false);
            $alleVariablen[$idents[0]] = $vid;
        }

        // WarnungBoe
        if (!array_key_exists($idents[1], $alleVariablen)) {
            $vid = $this->RegisterVariableBoolean($idents[1], "WarnungBoe: " . $name, "~Alert");
            IPS_SetHidden($vid, false);
            $alleVariablen[$idents[1]] = $vid;
        }

        // WarnCount
        if (!array_key_exists($idents[2], $alleVariablen)) {
            $vid = $this->RegisterVariableInteger($idents[2], "WarnCount: " . $name);
            IPS_SetHidden($vid, false);
            $alleVariablen[$idents[2]] = $vid;
        }

        // WarnCountBoe
        if (!array_key_exists($idents[3], $alleVariablen)) {
            $vid = $this->RegisterVariableInteger($idents[3], "WarnCountBoe: " . $name);
            IPS_SetHidden($vid, false);
            $alleVariablen[$idents[3]] = $vid;
        }

        // WarnModus
        if (!array_key_exists($idents[4], $alleVariablen)) {
            $vid = $this->RegisterVariableInteger($idents[4], "Warnmodus: " . $name, "WindPro.Schutzmodus");
            IPS_SetHidden($vid, false);
            $alleVariablen[$idents[4]] = $vid;

            // 🧰 Aktionsskript erzeugen und zuweisen
            $this->CreateActionScriptForIdent($idents[4], $vid);
        } else {
            $vid = $alleVariablen[$idents[4]];
        }

        if($formSetup) {
            // 🔄 Wert aus Form setzen
            $modus = isset($eintrag["Warnmodus"]) ? (int)$eintrag["Warnmodus"] : 0;
            SetValueInteger($vid, $modus);
            IPS_LogMessage("WMP-ApplyChanges", "Warnmodus gesetzt: $idents[4] = $modus (VarID $vid)");
        }



        // Status
        if (!array_key_exists($idents[5], $alleVariablen)) {
            $vid = $this->RegisterVariableString($idents[5], "Status: " . $name);
            IPS_SetHidden($vid, false);
            $alleVariablen[$idents[5]] = $vid;

            $boeVorschauPreset = "{}";
            $statusPreset = $this->getStatusPresetArray(
                $eintrag,
                0,
                0,
                isset($eintrag["RichtungsKuerzelListe"])
                    ? array_filter(array_map('trim', explode(',', $eintrag["RichtungsKuerzelListe"])))
                    : [],
                $boeVorschauPreset
            );

            SetValueString($vid, json_encode($statusPreset));
            IPS_LogMessage("WMP-ApplyChanges", "Status-Variable mit Preset initialisiert: $idents[5] (VarID $vid)");
        }
    }

    return [$alleVariablen, $genutzteIdents];
}

    private function CleanupUnusedVariables(array $alleVariablen, array $genutzteIdents): void
    {
        foreach ($alleVariablen as $ident => $objID) {
            if (!in_array($ident, $genutzteIdents)) {
                IPS_LogMessage("WindMonitorPro", "ℹ️ Entferne überflüssige Statusvariable '$ident'");
                IPS_DeleteVariable($objID);
            }
        }
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
        SetValue($this->GetIDForIdent("LetzterFetch"), $now);

    }

    private function getStatusPresetArray(
    array  $schutzObjektBasicData,    
    //string $name = "",
    //int $modus,
    //float $hoehe = 0,
    //float $minWind = 0,
    //float $minGust = 0,
    float $windInObjHoehe = 0,
    float $boeInObjHoehe = 0,
    array $kuerzelArray = [],
    string $boeVorschauJson = "{}"   // Default: leerer JSON-String fuer boeVorschau
    ): array
    {
        // Validierung, dass $boeVorschauJson ein gultiger JSON-String ist
        if (!is_string($boeVorschauJson) || trim($boeVorschauJson) === '') {
            $boeVorschauJson = "{}";
        }

        //Basiswerte aus Schutzarray der Formeingaben kopieren:
        $schutzObjektData = $schutzObjektBasicData; 
        //und zum kompletten Datenarry erweitern
        $schutzObjektData['wind']               = round($windInObjHoehe, 1);
        $schutzObjektData['boe']                = round($boeInObjHoehe, 1);
        $schutzObjektData['richtungsliste']     = $kuerzelArray;
        $schutzObjektData['warnsource']         = "";
        $schutzObjektData['warnungTS']          = "";
        $schutzObjektData['warnWind']           = false;
        $schutzObjektData['warnGust']           = false;
        $schutzObjektData['countWind']          = 0;
        $schutzObjektData['countGust']          = 0;
        $schutzObjektData['nachwirk']           = 0;
        // boeVorschau ist hier ein reiner JSON-String (roher Text)
        $schutzObjektData['boeVorschau']        = $boeVorschauJson;
        return $schutzObjektData;

    
    


/*
        return [
            'objekt'         => ($name === null || $name === '') ? '' : $name,
            'hoehe'          => $hoehe,
            'restzeit'       => "",
            'limitWind'      => round($minWind, 1),
            'wind'           => round($windInObjHoehe, 1),
            'limitBoe'       => round($minGust, 1),
            'boe'            => round($boeInObjHoehe, 1),
            'richtungsliste' => $kuerzelArray,
            'warnsource'     => "",
            'warnungTS'      => "",
            'warnWind'       => false,
            'warnGust'       => false,
            'modus'          => $modus,
            'countWind'      => 0,
            'countGust'      => 0,
            'nachwirk'       => 0,
            // boeVorschau ist hier ein reiner JSON-String (roher Text)
            'boeVorschau'    => $boeVorschauJson
        ];

*/

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



    public function erzeugeSchutzDashboard(array $schutzArray, int $instanceID): string {
     
        @$updateVarID = @IPS_GetObjectIDByIdent("UTC_ModelRun", $instanceID);
        $updateMBString = ($updateVarID && IPS_VariableExists($updateVarID)) ? GetValueString($updateVarID) : '';
        @$updateVarID = @IPS_GetObjectIDByIdent("LetzteAuswertungDaten", $instanceID);
        $updateString = ($updateVarID && IPS_VariableExists($updateVarID)) ? GetValueString($updateVarID) : '';

        if ($updateMBString !== '' && preg_match('/^(\d{2}\.\d{2}\.\d{4})\s+(\d{2}:\d{2}):\d{2}$/', $updateMBString, $m)) {
            $standMBText = $m[1] . ' ' . $m[2] . ' Uhr';
        } else {
            $standMBText = $updateMBString !== '' ? $updateMBString : '–';
        }
        if ($updateString !== '' && preg_match('/^(\d{2}\.\d{2}\.\d{4})\s+(\d{2}:\d{2}):\d{2}$/', $updateString, $m)) {
            $standText = $m[1] . ' ' . $m[2] . ' Uhr';
        } else {
            $standText = $updateString !== '' ? $updateString : '–';
        }

        // Nur minimale, neutrale Struktur:
        //$html = "<div>";
        $html = "<div id='neo-wrapper'>";
            $html .= "<div id='dashboard-title'>
                Schutzobjekt-Übersicht<br>
                <span>(MeteoBlue-Update vom: $standMBText; Datei gelesen: $standText)</span>
            </div>"; // falls die Ueberschrift komplett vom Webseitenelement formartiert wird

        $html .= "<table border='1' cellspacing='0' cellpadding='3'>";

        $html .= "<style>
            th {
            text-align: center;
            vertical-align: middle;
            }
        </style>";

        // Tabellenkopf
        $html .= "<tr>
            <th>Name</th>
            <th>Höhe</th>
            <th>Wind</th>
            <th>Böe</th>
            <th>Richtung</th>
            <th>Status</th>
            <th>Restzeit Warn</th>
            <th>Zähler</th>
        </tr>";
    /*  Aufbau Statusarry fuer Shutzobjekte:
                'objekt'      => ($name === null || $name === '') ? '' : $name,
                'hoehe'       => $hoehe,
                'restzeit'    => "",
                'limitWind'   => round($minWind, 1),
                'wind'        => round($windInObjHoehe, 1),
                'limitBoe'    => round($minGust, 1),
                'boe'         => round($boeInObjHoehe, 1),
                'richtungsliste' => $kuerzelArray
                'warnsource'  => "",
                'warnungTS'   => "",
                'warnWind'    => false,
                'warnGust'    => false,
                'countWind'   => 0,
                'countGust'   => 0,
                'nachwirk'    => 0,
                'boeVorschau' => $BoeGefahrVorschau
    */

        foreach ($schutzArray as $objekt) {
            $html .= $this->ErzeugePrognoseTabelle($objekt, $instanceID);

        }
        $html .= "</table></div>";
        return $html;
    }
    private function ErzeugePrognoseTabelle($objekt, int $instanceID) {   
        $label = $objekt["Label"] ?? "–";
        $ident = preg_replace('/\W+/', '_', $label);
        $idstatusStr = @IPS_GetObjectIDByIdent("Status_" . $ident, $instanceID);
        if ($idstatusStr === false) {
            IPS_LogMessage("WindMonitorPro", "Statusvariable für $ident nicht gefunden.");
            return '';
        }
        $statusJson = GetValueString($idstatusStr);
        $StatusValues = json_decode($statusJson, true);
            if ($statusJson === '' || !is_array($StatusValues)) {
                // Fehlerbehandlung: JSON ist ungültig oder ist kein Array
                return "";
            }


        $hoehe = $StatusValues["Hoehe"] ?? "–";
        $minWind = $StatusValues["MinWind"] ?? "–";
        $minGust = $StatusValues["MinGust"] ?? "–";
        $richtung = $objekt["RichtungsKuerzelListe"] ?? "–"; //Hole aus Schutzobjekt da hier als String abgelegt und so fuer HTML Ausgabe benoetigt wird
        $zaehlerWind = $StatusValues["countWind"] ?? "–";
        $zaehlerBoe = $StatusValues["countGust"] ?? "–";
        $zaehler = $zaehlerWind + $zaehlerBoe;



        $vid = @IPS_GetObjectIDByIdent("Warnung_" . preg_replace('/\W+/', '_', $label), $instanceID);
        $vidBoe = @IPS_GetObjectIDByIdent("WarnungBoe_" . preg_replace('/\W+/', '_', $label), $instanceID);
        $wind = ($vid !== false && IPS_VariableExists($vid)) ? GetValueBoolean($vid) : false;
        $Boe = ($vidBoe !== false && IPS_VariableExists($vidBoe)) ? GetValueBoolean($vidBoe) : false;
        $warnung = $wind || $Boe;
        $status = $warnung ? "⚠️ Aktiv" : "✅ Inaktiv";

        $countID = @IPS_GetObjectIDByIdent("WarnCount_" . preg_replace('/\W+/', '_', $label), $instanceID);
        //$zaehler = ($countID !== false && IPS_VariableExists($countID)) ? GetValueInteger($countID) : "–";

        // Zeit letzte Warnung und Prognose 
        $tsID = @IPS_GetObjectIDByIdent("LetzteWarnungTS_" . preg_replace('/\W+/', '_', $label), $instanceID);
        $tsText = ($tsID !== false && IPS_VariableExists($tsID)) ? date("H:i", GetValueInteger($tsID)) . " Uhr" : "–";

        // Json-Status
        $vid = @IPS_GetObjectIDByIdent("Status_" . preg_replace('/\W+/', '_', $label), $instanceID);
        $JsonProperties = $vid ? GetValueString($vid) : "";
        $properties = json_decode($JsonProperties, true) ?: [];
        $JsonWindPrognose = $properties['boeVorschau'] ?? "null";
        $prognose = json_decode($JsonWindPrognose, true) ?: [];
        $DatumPrognose = $prognose['datum'] ?? '–';
        $TimePrognose  = $prognose['uhrzeit'] ?? '–';
        $WindPrognose  = isset($prognose['wert']) && $prognose['wert'] !== null ? number_format($prognose['wert'], 2, ',', '') : '–';
        $WindDirection = $prognose['richtung'] ?? '–';
        $RestZeitWarnung = $properties['restzeit'] ?? '–';

        // Wochentag einfügen
        $dt = DateTime::createFromFormat('d.m.Y', $DatumPrognose);
        $wochentage = ['So','Mo','Di','Mi','Do','Fr','Sa'];
        if ($dt) {
            $dayShort = $wochentage[$dt->format('w')];
            $datumMitTag = "$dayShort, $DatumPrognose";
        } else {
            $datumMitTag = $DatumPrognose;
        }
        $DatumPrognose = $datumMitTag;

        // Hauptzeile
        $html = ''; 
        $html .= "<tr>
            <td>$label</td>
            <td>{$hoehe} m</td>
            <td>{$minWind} m/s</td>
            <td>{$minGust} m/s</td>
            <td>$richtung</td>
            <td>$status</td>
            <td>$RestZeitWarnung</td>
            <td>$zaehler</td>
        </tr>";

        // Prognosezeile
        $html .= "<tr>
            <td colspan='8'>
                🌬️ Prognose für Limitüberschreitung:
                am Datum: <b>$DatumPrognose</b>
                um Uhrzeit: <b>$TimePrognose</b>,
                mit Wert: <b>$WindPrognose m/s</b>,
                Dir: <b>$WindDirection</b>
            </td>
        </tr>";

        // Leerzeile
        $html .= "<tr><td colspan='8'>&nbsp;</td></tr>";
        return $html;
    } 

}






?>
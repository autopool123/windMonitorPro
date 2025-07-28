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
        $schutzArray = json_decode($this->ReadPropertyString("Schutzobjekte"), true);
        foreach ($schutzArray as $eintrag) {
            $name = $eintrag["Label"] ?? "Unbenannt";
            $ident = "Warnung_" . preg_replace('/\W+/', '_', $name);
            $txtIdent = "Status_" . preg_replace('/\W+/', '_', $name);
            //Nur wenn die Variable auch existiert 
            if (@IPS_VariableExists($this->GetIDForIdent($ident))) {
                IPS_SetIcon($this->GetIDForIdent($ident), "Shield");
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
        //    IPS_LogMessage('WindMonitorPro', 'Nachwirkzeit wurde auf ReadIntervall angehoben, um Überschreiben zu vermeiden.');
        //}

        //Neuladen falls Anpassung erfolgt ist
        //$nachwirkzeitMin = $this->ReadPropertyInteger("NachwirkzeitMin");

        $aktiv = $this->ReadPropertyBoolean("Aktiv");

        // 🔧 Profile erstellen
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
        $this->RegisterVariableString("SchutzHTML", "Schutzstatus (HTML)", "~HTMLBox");
        $this->RegisterVariableString("LetzterFetch", "Letzter API-Abruf", "~TextBox");
        $this->RegisterVariableString("LetzteAuswertungDaten", "Letzte Dateiverarbeitung", "~TextBox");
        $this->RegisterVariableString("NachwirkEnde", "Nachwirkzeit endet um", "~TextBox");
        $this->RegisterVariableBoolean("FetchDatenVeraltet", "Daten zu alt", "~Alert");
        $this->RegisterVariableString("LetzteAktion", "Letzte Aktion");

        //Abrufintervalle und Nachwirkzeit
        $this->RegisterVariableString("FetchIntervalInfo", "Abrufintervall (Info)", "~TextBox");
        $this->RegisterVariableString("MaxDatenAlterInfo", "Max Alter MB-Daten", "~TextBox");
        $this->RegisterVariableString("ReadIntervalInfo", "Dateileseintervall (Info)", "~TextBox");
        $this->RegisterVariableString("NachwirkzeitInfo", "Nachwirkzeit (Info)", "~TextBox");



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

    //---------------------------------------------------------------------------
    //ZUM TEST DIE DATEN AUSWERTEN AUCH WENN INAKTIV    
    //$this->SetTimerInterval("ReadTimer", max(1, $readMin) * 60 * 1000);
    IPS_LogMessage("WindMonitorPro", "ReadTimer gestartet: $readMin in Minuten");
    //---------------------------------------------------------------------------


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

            case "ResetStatus":
                return $this->ResetSchutzStatus();

            default:
                throw new Exception("⚠️ Ungültiger Aktion-Identifier: " . $Ident);
        }
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

        $schutzArray = json_decode($this->ReadPropertyString("Schutzobjekte"), true);
        if (!is_array($schutzArray)) {
            IPS_LogMessage($logtag, "❌ Schutzobjekte Property enthält kein gültiges JSON.");
            return;
        }

        $SammelWarnung = false;
        foreach ($schutzArray as $objekt) {
            $name = $objekt["Label"] ?? "Unbenannt";
            $ident = preg_replace('/\W+/', '_', $name);
            $minWind = $objekt["MinWind"] ?? 10.0;
            $minGust = $objekt["MinGust"] ?? 14.0;
            $richtungsliste = $objekt["RichtungsKuerzelListe"] ?? "";
            $kuerzelArray = array_filter(array_map('trim', explode(',', $richtungsliste)));
            $hoehe = $objekt["Hoehe"] ?? WindToolsHelper::$zielHoeheStandard;
            $windInObjHoehe = WindToolsHelper::windUmrechnungSmart($wind, $messniveau, $hoehe, WindToolsHelper::$gelaendeAlpha);
            $boeInObjHoehe = WindToolsHelper::windUmrechnungSmart($gust, $messniveau, $hoehe, WindToolsHelper::$gelaendeAlpha);
            $idstatusStr = $this->GetIDForIdent("Status_" . $ident);
            if ($idstatusStr === false) {
                IPS_LogMessage($logtag, "Statusvariable für $ident nicht gefunden.");
                continue;
            }

            $NachwirkZeitString = GetValueString($this->GetIDForIdent("NachwirkzeitInfo"));
            $NachwirkZeit = (preg_match('/\d+/', $NachwirkZeitString, $match)) ? intval($match[0]) : $this->ReadPropertyInteger('ReadIntervall');
            $warnsource = "Eigene Wetterstation";
            $NewStatusArray = WindToolsHelper::berechneSchutzstatusMitNachwirkung(
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
                $name,
                $hoehe
            );

            $statusJson = GetValueString($idstatusStr);
            $StatusCheckValuesJson = json_decode($statusJson, true);

            if ($statusJson === '' || !is_array($StatusCheckValuesJson)) {
                $StatusCheckValuesJson = $this->getStatusPresetArray($name, $hoehe, 0, 0, 0, 0, $kuerzelArray, []);
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

        $html = WindToolsHelper::erzeugeSchutzDashboard($schutzArray, $this->InstanceID);
        SetValue($this->GetIDForIdent("SchutzDashboardHTML"), $html);

        IPS_LogMessage($logtag, "Eigene Wetterdaten ausgewertet");
    }


    private function getStatusPresetArray($name="", $hoehe=0, $minWind=0, $minGust=0, $windInObjHoehe=0, $boeInObjHoehe=0, $kuerzelArray ="", $BoeGefahrVorschau = [])
    {
        return [
            'objekt'      => ($name === null || $name === '') ? '' : $name,
            'hoehe'       => $hoehe,
            'restzeit'    => "",
            'limitWind'   => round($minWind, 1),
            'wind'        => round($windInObjHoehe, 1),
            'limitBoe'    => round($minGust, 1),
            'boe'         => round($boeInObjHoehe, 1),
            'richtungsliste' => $kuerzelArray,
            'warnsource'  => "",
            'warnungTS'   => "",
            'warnWind'    => false,
            'warnGust'    => false,
            'countWind'   => 0,
            'countGust'   => 0,
            'nachwirk'    => 0,
            'boeVorschau' => $BoeGefahrVorschau
        ];
    }


    private function AuswertenEigeneStation()
        {
            /*
            // Hier holst oder generierst du deinen JSON-String:
            $json = $this->HoleEigeneStationsDaten(); // eigene Methode oder direktes Einfügen
            
            // Setze die String-Variable
            SetValue('EigeneStationJSON', $json);
            */
            // Optional: Log oder Info
            IPS_LogMessage('WetterModul', 'Eigene Station ausgewertet');
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
            $this->SetValueSetValue("LetzteAktion", "⏱️ ReadFromFile übersprungen: Daten von $ModelZeit");
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


        $durchschnitt = WindToolsHelper::berechneDurchschnittswerte($block, $index, 4);
        $SpeedMS = $durchschnitt['avgWind'] ?? 0;
        $SpeedMaxMS = $durchschnitt['maxWind'] ?? 0;
        $GustMaxM = $durchschnitt['maxGust'] ?? 0;
        $DirGrad = $durchschnitt['avgDir'] ?? 0;
        
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
        SetValueFloat($this->GetIDForIdent("SpeedMS"), $SpeedMS);
        SetValueFloat($this->GetIDForIdent("SpeedMaxMS"), $SpeedMaxMS);
        SetValueFloat($this->GetIDForIdent("GustMaxMS"), $GustMaxM);
        SetValueInteger($this->GetIDForIdent("DirGrad"), $DirGrad);
        */



        //Erzeuge das Schutzobjekt-Array welches alle ueber die form.json erstellten Schutzobjekte samt Inhalt enthaelt 
        $schutzArray = json_decode($this->ReadPropertyString("Schutzobjekte"), true);
        //IPS_LogMessage("Debug", print_r($schutzArray, true));
        // Schritt 1: Alle vorhandenen Schutz-Variablen der Instanz in ein Array (alleVariablen) schreiben
        // Also ein Array mit allen bereits vorhandenen Schutzvariablen erstellen um spaeter zu pruefen ob eine Variable bereits vorhanden
        // oder neu erstellt werden muss
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
            if (strpos($ident, "Status_") === 0) {
                $alleVariablen[$ident] = $objID;
            }
        }

        // Schritt 2: Mittels Eintraegen im Schutzobjekt prüfen ob alle Variable vorhanden
        // und ggf, falls Neueintrag Variable anlegen

        //Lege ein Array $genutzteIdents[] an, welches sämtliche Identtexte fasst die fuer ein Schutzobjekt benoetigt werden 
        //generiere die Identtexte und schreibe diese in das Array. Aus diesem werden spaeter die Idents gholt m ein Statusvariable
        //zu lesen oder zu shreiben 
        $genutzteIdents = [];//erzeuge das array
        foreach ($schutzArray as $eintrag) {
            //generiere die Idents nach dem Schema: "Inhaltsbescheibung_Schutzobjektname"
            $name = $eintrag["Label"] ?? "Unbenannt";
            $ident = "Warnung_" . preg_replace('/\W+/', '_', $name);
            $identBoe = "WarnungBoe_" . preg_replace('/\W+/', '_', $name);
            $identWC = "WarnCount_" . preg_replace('/\W+/', '_', $name);
            $identWCBoe = "WarnCountBoe_" . preg_replace('/\W+/', '_', $name);
            $identStatus = "Status_" . preg_replace('/\W+/', '_', $name);
            //Beschreibe die Arrayfelder mit den erzeugten Idents
            $genutzteIdents[] = $ident;
            $genutzteIdents[] = $identBoe;
            $genutzteIdents[] = $identWC;
            $genutzteIdents[] = $identWCBoe;
            $genutzteIdents[] = $identStatus;

            //Pruefen ob Warnung_Name(Warnobjekt-Name) Variable existiert sonst erstellen
            if (!array_key_exists($ident, $alleVariablen)) {
                $vid = $this->RegisterVariableBoolean($ident, "Warnung: " . $name,"~Alert");
                IPS_SetHidden($vid, false); // oder true, je nach Wunsch
                $alleVariablen[$ident] = $vid;
            }

            //Pruefen ob WarnungBoe_Name(Warnobjekt-Name) Variable existiert sonst erstellen
            if (!array_key_exists($identBoe, $alleVariablen)) {
                $vid = $this->RegisterVariableBoolean($identBoe, "WarnungBoe: " . $name,"~Alert");
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

            //Pruefen ob Status_Name(Warnobjekt-Name) Variable existiert sonst erstellen
            if (!array_key_exists($identStatus, $alleVariablen)) {
                $vid = $this->RegisterVariableString($identStatus, "Status: " . $name);
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
        
        $SammelWarnung = false;
        foreach ($schutzArray as $objekt) {
            $name = $objekt["Label"] ?? "Unbenannt";
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

            //Check ob Windrichtung die Warnung fuer Schutzobjekt betrifft
            //$inSektor = WindToolsHelper::richtungPasst($richtung, $kuerzelArray);//wird jetzt in berechneSchutzstatusMitNachwirkung behandelt
            //Auf Warnstatus checken 

            $NachwirkZeitString = GetValueString($this->GetIDForIdent("NachwirkzeitInfo"));
            $NachwirkZeit = (preg_match('/\d+/', $NachwirkZeitString, $match)) ? intval($match[0]) : 10;
            $warnsource = "MeteoBlue-Daten";
            $NewStatusArray = WindToolsHelper::berechneSchutzstatusMitNachwirkung(
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
                $objekt["Label"] ?? "Unbenannt",
                $hoehe
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
                $this->getStatusPresetArray($name, $hoehe, 0, 0, 0, 0,$kuerzelArray, []);
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
        $html = WindToolsHelper::erzeugeSchutzDashboard($schutzArray, $this->InstanceID);
        SetValue($this->GetIDForIdent("SchutzDashboardHTML"), $html);

        //Rueckmeldung
        IPS_LogMessage("WindMonitorPro", "📁 Datei-Daten gelesen");
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
        SetValue($this->GetIDForIdent("LetzterFetch"), $now);

    }

}






?>
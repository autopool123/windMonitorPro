<?php

class windMonitorPro extends IPSModule {

    public function Create() {
        parent::Create();
        $this->RegisterVariableFloat("WindSpeed", "Windgeschwindigkeit (km/h)");
    }

    public function ApplyChanges() {
        parent::ApplyChanges();
    }
}
?>
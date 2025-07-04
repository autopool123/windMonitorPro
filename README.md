# WindMonitorPro 🌬️🛡️

**IP-Symcon-Modul zur Überwachung von Winddaten und dynamischer Schutzlogik basierend auf Meteoblue-Vorhersagen.**

---

## 🚀 Funktionen

- Abruf von Meteoblue-Wetterdaten per API
- Lokale Speicherung als JSON zur Auswertung
- Dynamische Schutzobjekte mit individuellen Grenzwerten und Windsektoren
- Automatische Erstellung und Löschung von Schutzvariablen
- Berechnung und Anzeige von Windrichtungstext und Symbol
- Echtzeitprüfung auf veraltete Daten inkl. Sperrfunktionen

---

## 🛠️ Installation

1. Repo in IP-Symcon als Modul einbinden:
2. Instanz `WindMonitorPro` anlegen  
3. API-Key bei [meteoblue.com](https://www.meteoblue.com/) erstellen und ins Modul eintragen  
4. Standortdaten und Paket auswählen  
5. Schutzobjekte konfigurieren (Windgrenze, Böengrenze, Windrichtungskürzel usw.)

---

## 🔧 Konfigurationselemente (form.json)

| Einstellung        | Beschreibung |
|-------------------|--------------|
| `APIKey`          | Dein persönlicher API-Schlüssel von Meteoblue |
| `PackageSuffix`   | z. B. `basic-1h_wind-15min,current` |
| `Latitude/Longitude` | Standortkoordinaten |
| `Altitude`        | Höhe in Metern |
| `Dateipfad`       | Speicherort der JSON-Datei |
| `FetchJSON`       | Automatisch gespeicherter Roh-JSON-String |
| `Schutzobjekte`   | Liste der Objekte mit Grenzwerten und Sektorlogik |

---

## 📦 Beispiel: Schutzobjekt

```json
{
"Label": "Solaranlage",
"MinWind": 8.0,
"MinGust": 12.0,
"RichtungsKuerzelListe": "W,NW"
}



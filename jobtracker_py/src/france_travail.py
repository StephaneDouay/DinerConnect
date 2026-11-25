import requests
import time


class FranceTravailAPI:
    TOKEN_URL = "https://entreprise.francetravail.fr/connexion/oauth2/access_token"
    SEARCH_URL = "https://api.francetravail.io/partenaire/offresdemploi/v2/offres/search"

    def __init__(self, client_id, client_secret):
        self.client_id = client_id
        self.client_secret = client_secret
        self._token = None
        self._token_expires = 0

    def _get_token(self):
        if self._token and time.time() < self._token_expires - 60:
            return self._token
        resp = requests.post(
            self.TOKEN_URL,
            params={"realm": "/partenaire"},
            data={
                "grant_type": "client_credentials",
                "client_id": self.client_id,
                "client_secret": self.client_secret,
                "scope": "api_offresdemploiv2 o2dsoffre",
            },
            timeout=15,
        )
        resp.raise_for_status()
        data = resp.json()
        self._token = data["access_token"]
        self._token_expires = time.time() + data.get("expires_in", 1500)
        return self._token

    def search(self, keyword, lieu, rayon=30):
        if not self.client_id or not self.client_secret:
            return []
        try:
            token = self._get_token()
            params = {
                "motsCles": keyword,
                "commune": lieu if len(lieu) == 5 else None,
                "departement": lieu if len(lieu) <= 3 else None,
                "distance": rayon,
                "range": "0-49",
            }
            params = {k: v for k, v in params.items() if v is not None}
            resp = requests.get(
                self.SEARCH_URL,
                headers={"Authorization": f"Bearer {token}", "Accept": "application/json"},
                params=params,
                timeout=15,
            )
            resp.raise_for_status()
            data = resp.json()
            results = []
            for o in data.get("resultats", []):
                results.append({
                    "source": "francetravail",
                    "external_id": o.get("id", ""),
                    "titre": o.get("intitule", ""),
                    "entreprise": o.get("entreprise", {}).get("nom", ""),
                    "lieu": o.get("lieuTravail", {}).get("libelle", ""),
                    "url": o.get("origineOffre", {}).get("urlOrigine", ""),
                    "description": o.get("description", "")[:2000],
                    "date_pub": o.get("dateCreation", "")[:10],
                })
            return results
        except Exception as e:
            from src.logger import log
            log("error", "offres", f"France Travail error: {e}")
            return []

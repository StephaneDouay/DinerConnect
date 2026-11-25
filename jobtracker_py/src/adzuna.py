import requests


class AdzunaAPI:
    BASE_URL = "https://api.adzuna.com/v1/api/jobs/fr/search/1"

    def __init__(self, app_id, app_key):
        self.app_id = app_id
        self.app_key = app_key

    def search(self, keyword, location):
        if not self.app_id or not self.app_key:
            return []
        try:
            resp = requests.get(
                self.BASE_URL,
                params={
                    "app_id": self.app_id,
                    "app_key": self.app_key,
                    "what": keyword,
                    "where": location,
                    "results_per_page": 50,
                    "content-type": "application/json",
                },
                timeout=15,
            )
            resp.raise_for_status()
            data = resp.json()
            results = []
            for o in data.get("results", []):
                results.append({
                    "source": "adzuna",
                    "external_id": str(o.get("id", "")),
                    "titre": o.get("title", ""),
                    "entreprise": o.get("company", {}).get("display_name", ""),
                    "lieu": o.get("location", {}).get("display_name", ""),
                    "url": o.get("redirect_url", ""),
                    "description": o.get("description", "")[:2000],
                    "date_pub": o.get("created", "")[:10],
                })
            return results
        except Exception as e:
            from src.logger import log
            log("error", "offres", f"Adzuna error: {e}")
            return []

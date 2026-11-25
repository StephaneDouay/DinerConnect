import unicodedata
import re


def _normalize(text):
    if not text:
        return ""
    text = text.lower().strip()
    text = unicodedata.normalize("NFD", text)
    text = "".join(c for c in text if unicodedata.category(c) != "Mn")
    text = re.sub(r'\s+', ' ', text)
    return text


def fetch_and_store():
    import config
    from src.database import get_db
    from src.france_travail import FranceTravailAPI
    from src.adzuna import AdzunaAPI
    from src.logger import log

    db = get_db()
    ft_api = FranceTravailAPI(
        config.FRANCE_TRAVAIL.get("client_id", ""),
        config.FRANCE_TRAVAIL.get("client_secret", ""),
    )
    az_api = AdzunaAPI(
        config.ADZUNA.get("app_id", ""),
        config.ADZUNA.get("app_key", ""),
    )

    exclusions = [e.lower() for e in config.RECHERCHE.get("exclusions", [])]
    mots_cles = config.RECHERCHE.get("mots_cles", [])
    lieux = config.RECHERCHE.get("lieux", ["75"])
    rayon = config.RECHERCHE.get("rayon_km", 30)

    all_offres = []
    for kw in mots_cles:
        for lieu in lieux:
            all_offres += ft_api.search(kw, lieu, rayon)
            all_offres += az_api.search(kw, lieu)

    counts = {"francetravail": 0, "adzuna": 0}
    seen_norm = set()

    for o in all_offres:
        titre_low = o["titre"].lower()
        if any(ex in titre_low for ex in exclusions):
            continue

        norm_key = (_normalize(o["titre"]), _normalize(o.get("entreprise", "")))
        if norm_key in seen_norm:
            continue
        seen_norm.add(norm_key)

        try:
            db.execute(
                """INSERT OR IGNORE INTO offres
                   (source, external_id, titre, entreprise, lieu, url, description, date_pub)
                   VALUES (?,?,?,?,?,?,?,?)""",
                (o["source"], o["external_id"], o["titre"], o.get("entreprise", ""),
                 o.get("lieu", ""), o.get("url", ""), o.get("description", ""),
                 o.get("date_pub", ""))
            )
            if db.execute("SELECT changes()").fetchone()[0]:
                counts[o["source"]] = counts.get(o["source"], 0) + 1
        except Exception as e:
            log("error", "offres", str(e))

    db.commit()
    log("info", "offres", f"France Travail: {counts['francetravail']} nouvelles, Adzuna: {counts['adzuna']} nouvelles")
    return counts

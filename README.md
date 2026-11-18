# Mdfile : fichiers Markdown

Plugin DokuWiki (`mdfile`).

Ouvre, lit (rendu Markdown) et **modifie les fichiers `.md`** dans DokuWiki.

## Utilisation

- `?do=mdfile&media=espace:fichier.md` : lecture ; `&edit=1` : édition (aperçu, enregistrement avec historique du média).
- Les fichiers sont cherchés dans le gestionnaire de médias, puis dans le dossier des pages (`data/pages/espace/fichier.md`, nom d'origine accepté).
- Les liens `{{espace:fichier.md}}` ouvrent cette vue au lieu de télécharger le fichier.
- Déclarer le type MIME dans `conf/mime.local.conf` : `md text/markdown`, `markdown text/markdown`.

## Configuration

`exts` : extensions gérées (défaut `md,markdown`).

## Dépendances

Aucune. Le plugin `mmdfile` s'appuie sur celui-ci.

## Installation

Copier le contenu de ce dépôt dans `lib/plugins/mdfile/` (le fichier `plugin.info.txt` doit se trouver à la racine de ce dossier), puis vider le cache en rendant la date de `conf/local.php` plus récente (`touch conf/local.php`).

Avec le gestionnaire d'extensions de DokuWiki : *Administration → Gestionnaire d'extensions → Installer*, en indiquant l'adresse du dépôt (archive ZIP).

## Licence

Aucune licence n'a encore été choisie : à ajouter (fichier `LICENSE`) avant de publier le dépôt. DokuWiki est sous GPL-2.0.

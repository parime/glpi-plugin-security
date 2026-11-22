Fixture : dépendances vulnérables volontaires (composer et npm), check-dependencies.sh (Trivy) doit
les trouver. Les fichiers portent le suffixe .fixture : sous leur vrai nom, le graphe de dépendances
de GitHub ouvrirait des alertes Dependabot sur ce dépôt. L'auto-test les copie sous leur vrai nom
(composer.lock, package-lock.json) dans une copie temporaire de secbad.

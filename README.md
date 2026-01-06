# FrankenHostinger - Déployer Symfony avec FrankenPHP sur Hostinger

Tutoriel complet pour déployer une application Symfony 8 avec FrankenPHP sur un VPS Hostinger via Docker Compose.

## Prérequis

- Un VPS Hostinger (ou autre) avec Ubuntu/Debian
- Un nom de domaine pointant vers le VPS
- Un compte Docker Hub
- Un compte GitHub

## Architecture

```
┌─────────────────────────────────────────────────────┐
│                    VPS Hostinger                     │
│  ┌─────────────────┐    ┌─────────────────────────┐ │
│  │   FrankenPHP    │    │      PostgreSQL         │ │
│  │   (Symfony)     │───▶│      (Database)         │ │
│  │   Port 80/443   │    │      Port 5432          │ │
│  └─────────────────┘    └─────────────────────────┘ │
└─────────────────────────────────────────────────────┘
         ▲
         │ HTTPS
         │
    ┌────┴────┐
    │  Client │
    └─────────┘
```

---

## Partie 1 : L'application Symfony

### 1.1 Structure du projet

```
FrankenHostinger/
├── src/
│   ├── Controller/
│   │   └── CounterController.php    # Route unique
│   ├── Entity/
│   │   └── Counter.php              # Entité compteur
│   └── Repository/
│       └── CounterRepository.php
├── templates/
│   └── counter/
│       └── index.html.twig          # Interface
├── migrations/
│   └── Version*.php                 # Migration BDD
├── Dockerfile                       # Image FrankenPHP
├── compose.prod.yaml                # Docker Compose prod
├── .github/
│   └── workflows/
│       └── docker-publish.yml       # CI/CD GitHub Actions
└── .env.prod.example                # Variables d'environnement
```

### 1.2 Le Controller

```php
// src/Controller/CounterController.php
#[Route('/', name: 'app_counter', methods: ['GET', 'POST'])]
public function index(Request $request, CounterRepository $repo, EntityManagerInterface $em): Response
{
    $counter = $repo->getOrCreateCounter();

    if ($request->isMethod('POST')) {
        $counter->increment();
        $em->flush();
        return $this->redirectToRoute('app_counter');
    }

    return $this->render('counter/index.html.twig', ['counter' => $counter]);
}
```

---

## Partie 2 : Docker

### 2.1 Dockerfile

```dockerfile
FROM dunglas/frankenphp

# Dépendances système
RUN apt-get update && apt-get install -y unzip && rm -rf /var/lib/apt/lists/*

# Extension PostgreSQL
RUN install-php-extensions pdo_pgsql

# Installer Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Symfony en mode prod
ENV APP_ENV=prod

# Copier l'application
COPY . /app

# Installer les dépendances
RUN composer install --no-dev --optimize-autoloader --no-scripts \
    && composer dump-autoload --optimize
```

### 2.2 Docker Compose Production

```yaml
# compose.prod.yaml
services:
  app:
    image: yoanbernabeu/franken-hostinger:latest
    restart: unless-stopped
    ports:
      - "80:80"
      - "443:443"
    environment:
      APP_ENV: prod
      APP_SECRET: ${APP_SECRET}
      DATABASE_URL: postgresql://${POSTGRES_USER}:${POSTGRES_PASSWORD}@database:5432/${POSTGRES_DB}
      SERVER_NAME: ${SERVER_NAME}  # 👈 Domaine ici
    depends_on:
      database:
        condition: service_healthy

  database:
    image: postgres:16-alpine
    restart: unless-stopped
    environment:
      POSTGRES_USER: ${POSTGRES_USER}
      POSTGRES_PASSWORD: ${POSTGRES_PASSWORD}
      POSTGRES_DB: ${POSTGRES_DB}
    volumes:
      - database_data:/var/lib/postgresql/data
    healthcheck:
      test: ["CMD", "pg_isready", "-d", "${POSTGRES_DB}", "-U", "${POSTGRES_USER}"]
      interval: 10s
      timeout: 5s
      retries: 5

volumes:
  database_data:
```

### 2.3 Configuration du domaine

FrankenPHP utilise Caddy qui gère automatiquement les certificats SSL via Let's Encrypt.

**Variable `SERVER_NAME`** :
- `SERVER_NAME=example.com` → HTTPS automatique avec Let's Encrypt
- `SERVER_NAME=:80` → HTTP uniquement (pas de SSL)
- `SERVER_NAME=localhost` → Certificat auto-signé (dev)

---

## Partie 3 : CI/CD GitHub Actions

### 3.1 Workflow

```yaml
# .github/workflows/docker-publish.yml
name: Build and Push Docker Image

on:
  push:
    branches:
      - main

env:
  IMAGE_NAME: yoanbernabeu/franken-hostinger

jobs:
  build-and-push:
    runs-on: ubuntu-latest

    steps:
      - name: Checkout repository
        uses: actions/checkout@v4

      - name: Set up Docker Buildx
        uses: docker/setup-buildx-action@v3

      - name: Login to Docker Hub
        uses: docker/login-action@v3
        with:
          username: ${{ secrets.DOCKERHUB_USERNAME }}
          password: ${{ secrets.DOCKERHUB_TOKEN }}

      - name: Build and push Docker image
        uses: docker/build-push-action@v6
        with:
          context: .
          push: true
          tags: ${{ env.IMAGE_NAME }}:latest
          cache-from: type=gha
          cache-to: type=gha,mode=max
```

### 3.2 Configuration des secrets GitHub

1. Aller dans **Settings** → **Secrets and variables** → **Actions**
2. Créer les secrets :
   - `DOCKERHUB_USERNAME` : votre username Docker Hub
   - `DOCKERHUB_TOKEN` : votre access token Docker Hub

Pour créer un token Docker Hub :
1. Docker Hub → Account Settings → Security → New Access Token
2. Nom : "GitHub Actions"
3. Permissions : Read & Write

---

## Partie 4 : Déploiement sur VPS Hostinger

### 4.1 Configurer le pare-feu

Dans le panneau Hostinger (hPanel), aller dans **VPS** → **Firewall** et configurer les règles suivantes :

| Action | Protocole | Port | Source |
|--------|-----------|------|--------|
| Accept | TCP | 80 | Any |
| Accept | TCP | 443 | Any |
| Accept | UDP | 443 | Any |
| Drop | Any | Any | Any |

> **Note** : Le port UDP 443 est nécessaire pour HTTP/3 (QUIC).

### 4.2 Configurer le DNS

Chez votre fournisseur de nom de domaine, créer un enregistrement A pour pointer vers l'IP du VPS Hostinger.

### 4.3 Accéder au Docker Manager

1. Dans hPanel, aller dans **VPS** → Votre VPS
2. Cliquer sur **Docker Manager** dans le menu latéral

### 4.4 Déployer via "Compose from URL"

1. Cliquer sur **Compose from URL**
2. Coller l'URL du fichier compose :

```
https://raw.githubusercontent.com/yoanbernabeu/FrankenHostinger/main/compose.prod.yaml
```

3. Cliquer sur **Check URL** puis **Continue**

### 4.5 Configurer les variables d'environnement

Dans le formulaire, ajouter les **Environment Variables** :

| Variable | Valeur |
|----------|--------|
| `APP_SECRET` | `votre-cle-secrete-32-caracteres` |
| `SERVER_NAME` | `votre-domaine.com` |
| `POSTGRES_USER` | `app` |
| `POSTGRES_PASSWORD` | `votre-mot-de-passe-fort` |
| `POSTGRES_DB` | `franken_hostinger` |

Pour générer une clé secrète, utiliser :

```bash
openssl rand -hex 16
```

### 4.6 Configurer les ports

| Port VPS | Port Container |
|----------|----------------|
| `80` | `80` |
| `443` | `443` |

### 4.7 Déployer

1. Cliquer sur **Deploy**
2. Attendre que les containers démarrent (1-2 minutes)

### 4.8 Vérifier le SSL

Ouvrir `https://votre-domaine.com` dans un navigateur. Le certificat SSL Let's Encrypt est généré automatiquement par FrankenPHP/Caddy.

> **Note** : Les migrations sont maintenant exécutées automatiquement au démarrage du conteneur.

---

## Partie 5 : Mise à jour de l'application

### 5.1 Workflow de mise à jour

1. **Développer** localement
2. **Push** sur GitHub (branche main)
3. **GitHub Actions** build et push l'image sur Docker Hub
4. **Sur Hostinger** : re-déployer le projet

### 5.2 Re-déployer via Docker Manager

1. Dans **Docker Manager**, cliquer sur votre projet
2. Cliquer sur **Redeploy** ou **Pull & Restart**
3. Attendre que la nouvelle image soit téléchargée

### 5.3 Migrations automatiques

Les migrations sont exécutées automatiquement au démarrage du conteneur. Aucune action manuelle n'est nécessaire.

---

## Commandes utiles (SSH)

```bash
# Lister les containers
docker ps

# Voir les logs en temps réel
docker logs -f <container_app_id>

# Accéder au shell du container
docker exec -it <container_app_id> bash

# Exécuter une commande Symfony
docker exec -it <container_app_id> bin/console cache:clear
docker exec -it <container_app_id> bin/console doctrine:migrations:status

# Voir l'utilisation des ressources
docker stats
```

---

## Troubleshooting

### Le certificat SSL ne fonctionne pas

1. Vérifier que le domaine pointe bien vers le VPS :

   ```bash
   dig +short votre-domaine.com
   ```

2. Vérifier que les ports 80 et 443 sont mappés dans Docker Manager

3. Vérifier les logs Caddy :

   ```bash
   docker logs <container_app_id> | grep -i "tls\|certificate"
   ```

### Erreur de connexion à la base de données

1. Vérifier que les containers tournent :

   ```bash
   docker ps
   ```

2. Vérifier les logs de la base :

   ```bash
   docker logs <container_database_id>
   ```

### L'application affiche une erreur 500

1. Vérifier les logs PHP :

   ```bash
   docker logs <container_app_id>
   ```

2. Vérifier que APP_SECRET est défini dans les variables d'environnement du Docker Manager

---

## Ressources

- [Hostinger Docker Manager](https://www.hostinger.com/support/12040815-how-to-deploy-your-first-container-with-hostinger-docker-manager/)
- [Documentation FrankenPHP](https://frankenphp.dev/docs/)
- [Documentation Symfony](https://symfony.com/doc/current/index.html)
- [Docker Hub - dunglas/frankenphp](https://hub.docker.com/r/dunglas/frankenphp)
- [Caddy - Automatic HTTPS](https://caddyserver.com/docs/automatic-https)

---

## Licence

MIT

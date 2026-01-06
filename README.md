# FrankenHostinger - Déployer Symfony avec FrankenPHP sur Hostinger

> **Ce projet est une démonstration à but pédagogique** réalisée dans le cadre d'une vidéo pour la chaîne YouTube [YoanDev](https://www.youtube.com/@yoandevco), en partenariat commercial avec Hostinger.
>
> **Offre spéciale** : Code promo **YOANDEV10** (valable uniquement sur les plans de 12 mois et plus)
>
> [Obtenir l'offre sur Hostinger](https://www.hostinger.fr/yoandev10)

---

Tutoriel complet pour déployer une application Symfony 8 avec FrankenPHP sur un VPS Hostinger via Docker Compose.

## Prérequis

- Un VPS Hostinger
- Un nom de domaine pointant vers le VPS
- Un compte Docker Hub
- Un compte GitHub

## Architecture

```text
┌─────────────────────────────────────────────────────┐
│                    VPS Hostinger                    │
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

> **FrankenPHP** est un serveur d'application PHP moderne créé par Kévin Dunglas. Il embarque Caddy (serveur web avec HTTPS automatique) et PHP dans un seul binaire. Fini la configuration nginx + PHP-FPM : un seul conteneur suffit !

---

## Partie 1 : Créer l'application Symfony

### 1.1 Initialiser le projet

```bash
symfony new FrankenHostinger --webapp
cd FrankenHostinger
```

### 1.2 Structure finale du projet

```text
FrankenHostinger/
├── src/
│   ├── Controller/
│   │   └── CounterController.php
│   ├── Entity/
│   │   └── Counter.php
│   └── Repository/
│       └── CounterRepository.php
├── templates/
│   └── counter/
│       └── index.html.twig
├── migrations/
│   └── Version*.php
├── Dockerfile
├── docker-entrypoint.sh
├── compose.prod.yaml
└── .github/
    └── workflows/
        └── docker-publish.yml
```

### 1.3 Créer l'entité Counter

```bash
php bin/console make:entity Counter
```

Fichier `src/Entity/Counter.php` :

```php
<?php

namespace App\Entity;

use App\Repository\CounterRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CounterRepository::class)]
class Counter
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $value = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getValue(): int
    {
        return $this->value;
    }

    public function setValue(int $value): static
    {
        $this->value = $value;
        return $this;
    }

    public function increment(): static
    {
        $this->value++;
        return $this;
    }
}
```

### 1.4 Créer le Repository

Fichier `src/Repository/CounterRepository.php` :

```php
<?php

namespace App\Repository;

use App\Entity\Counter;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Counter>
 */
class CounterRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Counter::class);
    }

    public function getOrCreateCounter(): Counter
    {
        $counter = $this->find(1);

        if (!$counter) {
            $counter = new Counter();
            $this->getEntityManager()->persist($counter);
            $this->getEntityManager()->flush();
        }

        return $counter;
    }
}
```

### 1.5 Créer le Controller

```bash
php bin/console make:controller CounterController
```

Fichier `src/Controller/CounterController.php` :

```php
<?php

namespace App\Controller;

use App\Repository\CounterRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class CounterController extends AbstractController
{
    #[Route('/', name: 'app_counter', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        CounterRepository $counterRepository,
        EntityManagerInterface $entityManager
    ): Response {
        $counter = $counterRepository->getOrCreateCounter();

        if ($request->isMethod('POST')) {
            $counter->increment();
            $entityManager->flush();

            return $this->redirectToRoute('app_counter');
        }

        return $this->render('counter/index.html.twig', [
            'counter' => $counter,
        ]);
    }
}
```

### 1.6 Créer le template

Fichier `templates/counter/index.html.twig` :

```twig
{% extends 'base.html.twig' %}

{% block title %}FrankenHostinger Counter{% endblock %}

{% block body %}
    <div style="text-align: center; margin-top: 50px; font-family: system-ui, sans-serif;">
        <h1>FrankenHostinger Counter</h1>

        <div style="font-size: 4rem; margin: 30px 0; font-weight: bold;">
            {{ counter.value }}
        </div>

        <form method="post" action="{{ path('app_counter') }}">
            <button type="submit" style="
                padding: 15px 30px;
                font-size: 1.2rem;
                cursor: pointer;
                background-color: #7952B3;
                color: white;
                border: none;
                border-radius: 5px;
            ">
                Increment Counter
            </button>
        </form>

        <p style="margin-top: 30px; color: #666;">
            Powered by Symfony 8.0 + FrankenPHP
        </p>
    </div>
{% endblock %}
```

### 1.7 Créer la migration

```bash
php bin/console make:migration
```

---

## Partie 2 : Docker

> **Pourquoi Docker ?** Il permet de packager l'application avec toutes ses dépendances dans une image. L'environnement est identique en dev et en prod, et le déploiement se fait en quelques clics.

### 2.1 Dockerfile

Créer le fichier `Dockerfile` à la racine.

> On utilise l'image officielle `dunglas/frankenphp` qui contient déjà PHP 8.5 et Caddy. On ajoute juste les extensions nécessaires (PostgreSQL, intl) et on installe les dépendances Composer.

```dockerfile
FROM dunglas/frankenphp

# Dépendances système
RUN apt-get update && apt-get install -y unzip && rm -rf /var/lib/apt/lists/*

# Extensions PHP
RUN install-php-extensions pdo_pgsql intl

# Installer Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Symfony en mode prod
ENV APP_ENV=prod

# Copier l'application
COPY . /app

# Installer les dépendances (APP_ENV=prod évite le chargement des bundles dev)
RUN composer install --no-dev --optimize-autoloader --no-scripts \
    && composer dump-autoload --optimize \
    && php bin/console importmap:install \
    && php bin/console asset-map:compile

# Script d'entrypoint pour init DB + migrations
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["docker-entrypoint.sh"]
```

### 2.2 Script d'entrypoint

Créer le fichier `docker-entrypoint.sh` à la racine.

> **Pourquoi un entrypoint ?** Ce script s'exécute à chaque démarrage du conteneur. Il attend que PostgreSQL soit prêt, crée la base de données si nécessaire, et exécute les migrations automatiquement. Résultat : zéro intervention manuelle après déploiement !

```bash
#!/bin/bash
set -e

echo "Waiting for database to be ready..."
until php bin/console doctrine:database:create --if-not-exists --no-interaction 2>&1; do
    echo "Database not ready, waiting..."
    sleep 3
done

echo "Database is ready!"

echo "Running migrations..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

echo "Clearing cache..."
php bin/console cache:clear --no-interaction

echo "Starting FrankenPHP..."
exec frankenphp run --config /etc/caddy/Caddyfile
```

### 2.3 Docker Compose Production

Créer le fichier `compose.prod.yaml`.

> **Astuce : les valeurs par défaut** avec la syntaxe `${VAR:-default}`. Si la variable n'est pas définie, la valeur après `:-` est utilisée. Cela permet un déploiement "zero config" pour tester rapidement, tout en gardant la possibilité de personnaliser en prod.

```yaml
services:
  app:
    image: yoanbernabeu/franken-hostinger:latest
    restart: unless-stopped
    ports:
      - "80:80"
      - "443:443"
      - "443:443/udp"
    environment:
      APP_ENV: prod
      APP_SECRET: ${APP_SECRET:-change_me_in_production_with_secure_secret}
      DATABASE_URL: postgresql://${POSTGRES_USER:-app}:${POSTGRES_PASSWORD:-S3cur3P4ssw0rd2024}@database:5432/${POSTGRES_DB:-app}?serverVersion=16&charset=utf8
      SERVER_NAME: ${SERVER_NAME:-:80}
    volumes:
      - caddy_data:/data
      - caddy_config:/config
    depends_on:
      database:
        condition: service_healthy

  database:
    image: postgres:16-alpine
    restart: unless-stopped
    environment:
      POSTGRES_USER: ${POSTGRES_USER:-app}
      POSTGRES_PASSWORD: ${POSTGRES_PASSWORD:-S3cur3P4ssw0rd2024}
      POSTGRES_DB: ${POSTGRES_DB:-app}
    volumes:
      - database_data:/var/lib/postgresql/data
    healthcheck:
      test: ["CMD", "pg_isready", "-d", "${POSTGRES_DB:-app}", "-U", "${POSTGRES_USER:-app}"]
      interval: 10s
      timeout: 5s
      retries: 5

volumes:
  database_data:
  caddy_data:
  caddy_config:
```

### 2.4 Configuration du domaine

FrankenPHP utilise Caddy qui gère automatiquement les certificats SSL via Let's Encrypt.

**Variable `SERVER_NAME`** :

- `SERVER_NAME=example.com` → HTTPS automatique avec Let's Encrypt
- `SERVER_NAME=:80` → HTTP uniquement (pas de SSL)
- `SERVER_NAME=localhost` → Certificat auto-signé (dev)

---

## Partie 3 : CI/CD GitHub Actions

> **CI/CD automatisé** : À chaque push sur `main`, GitHub Actions build l'image Docker et la pousse sur Docker Hub. Hostinger peut ensuite récupérer cette image publique sans configuration supplémentaire.
>
> **Note** : Pour cet exemple, on utilise un registry Docker public (Docker Hub). En production, vous pouvez utiliser un registry privé (GitHub Container Registry, GitLab Registry, AWS ECR, etc.) pour protéger votre code.

### 3.1 Créer le workflow

Créer le fichier `.github/workflows/docker-publish.yml` :

```yaml
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

> **Docker Manager** est l'interface Hostinger pour gérer les conteneurs Docker sur votre VPS. Il permet de déployer directement depuis une URL de fichier Docker Compose, sans avoir à se connecter en SSH.

### 4.1 Configurer le pare-feu

Dans le panneau Hostinger (hPanel), aller dans **VPS** → **Firewall** et configurer les règles suivantes :

| Action | Protocole | Port | Source |
| ------ | --------- | ---- | ------ |
| Accept | TCP       | 80   | Any    |
| Accept | TCP       | 443  | Any    |
| Accept | UDP       | 443  | Any    |
| Drop   | Any       | Any  | Any    |

> **Note** : Le port UDP 443 est nécessaire pour HTTP/3 (QUIC).

### 4.2 Configurer le DNS

Chez votre fournisseur de nom de domaine, créer un enregistrement A pour pointer vers l'IP du VPS Hostinger.

### 4.3 Accéder au Docker Manager

1. Dans hPanel, aller dans **VPS** → Votre VPS
2. Cliquer sur **Docker Manager** dans le menu latéral

### 4.4 Déployer via "Compose from URL"

1. Cliquer sur **Compose from URL**
2. Coller l'URL du fichier compose :

```text
https://raw.githubusercontent.com/yoanbernabeu/FrankenHostinger/main/compose.prod.yaml
```

3. Cliquer sur **Check URL** puis **Continue**

### 4.5 Configurer les variables d'environnement (optionnel)

Les variables ont des valeurs par défaut, mais vous pouvez les personnaliser :

| Variable          | Valeur par défaut                  |
| ----------------- | ---------------------------------- |
| `APP_SECRET`      | `change_me_in_production...`       |
| `SERVER_NAME`     | `:80` (HTTP)                       |
| `POSTGRES_USER`   | `app`                              |
| `POSTGRES_PASSWORD` | `S3cur3P4ssw0rd2024`             |
| `POSTGRES_DB`     | `app`                              |

Pour le HTTPS avec Let's Encrypt, définir `SERVER_NAME=votre-domaine.com`.

### 4.6 Configurer les ports

| Port VPS | Port Container |
| -------- | -------------- |
| `80`     | `80`           |
| `443`    | `443`          |
| `443`    | `443/udp`      | (HTTP/3)

### 4.7 Déployer

1. Cliquer sur **Deploy**
2. Attendre que les containers démarrent (1-2 minutes)

### 4.8 Vérifier le SSL

Ouvrir `https://votre-domaine.com` dans un navigateur. Le certificat SSL Let's Encrypt est généré automatiquement par FrankenPHP/Caddy.

> **Note** : Les migrations sont exécutées automatiquement au démarrage du conteneur.

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

[MIT License](LICENSE) - Yoan Bernabeu 2026

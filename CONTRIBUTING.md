# Contributing to RendezVox

Thanks for your interest in contributing!

## How to Contribute

1. **Report bugs** — Use the [Bug Report](https://github.com/chardric/rendezvox/issues/new?template=bug.yml) template
2. **Request features** — Use the [Feature Request](https://github.com/chardric/rendezvox/issues/new?template=feature.yml) template
3. **Submit code** — Fork, create a branch, submit a Pull Request

## Development Setup

### Prerequisites
- Docker & Docker Compose
- Git

### Running Locally
```bash
cd docker
docker compose up -d
```
Admin panel: http://localhost/admin/

### Code Standards
- **PHP**: `declare(strict_types=1)`, typed parameters and return types, parameterized SQL queries
- **JavaScript**: Vanilla JS (no frameworks), no build step
- **Security**: All user input must be sanitized. Follow OWASP guidelines.

### Before Submitting a PR
- Lint PHP: `php -l src/path/to/file.php`
- Lint JS: `node -c public/admin/js/file.js`
- Test on both x86 and ARM (if possible)
- Fill in the PR template completely

## Code of Conduct

Be respectful and constructive. We're all here to make RendezVox better.

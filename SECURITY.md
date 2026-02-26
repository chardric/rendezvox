# Security Policy

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| 1.x     | :white_check_mark: |

## Reporting a Vulnerability

If you discover a security vulnerability in RendezVox, please report it responsibly.

**Do NOT open a public GitHub issue for security vulnerabilities.**

Instead, please email: **security@downstreamtech.net**

Include:
- Description of the vulnerability
- Steps to reproduce
- Potential impact
- Suggested fix (if any)

You will receive a response within 48 hours. We will work with you to understand and address the issue before any public disclosure.

## Security Measures

RendezVox implements the following security practices:
- All SQL queries use parameterized statements (PDO prepared)
- User input is sanitized and escaped in all HTML output
- JWT authentication with HMAC-SHA256 signatures
- Rate limiting at both Nginx and application layers
- Security headers on all responses (X-Frame-Options, CSP, etc.)
- File upload validation (MIME type, size, content inspection)
- No hardcoded credentials (all via environment variables)
- Path traversal prevention on all file operations

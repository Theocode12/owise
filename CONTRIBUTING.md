# Contributing

Contributions are welcome via pull requests on [GitHub](https://github.com/Theocode12/laravel-wise).

## Workflow

1. Fork the repository and create a branch from `main`.
2. Run `composer install`.
3. Add tests for your change.
4. Make sure `composer qa` passes (Pint check, Larastan and Pest).
5. Open a pull request describing what changed and why.

## Guidelines

- Follow the existing code style; run `composer lint` to fix formatting.
- Keep pull requests focused on a single change.
- Update `CHANGELOG.md` under "Unreleased" for user-facing changes.
- Never commit real Wise credentials. Use the Wise sandbox and fake HTTP responses in tests.

## Security

Please do not report security vulnerabilities in public issues. Use GitHub's private
vulnerability reporting on the repository instead.

# Contributing

Contributions are welcome via pull requests on [GitHub](https://github.com/Theocode12/owise).

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
- Review Dependabot updates like other pull requests: check the dependency/action changes and merge only after the required CI checks and independent review pass.

## Security

Please do not report security vulnerabilities in public issues. Use GitHub's private
vulnerability reporting on the repository instead.

## Releasing

Owise package releases use Semantic Versioning independently of Wise's quarterly API version. Stay on `0.x` until the sandbox
end-to-end flow has been verified and a production pilot completed. Before that milestone, use a minor-version bump for
breaking changes, a minor bump for new features, and a patch bump for backward-compatible fixes. Once `1.0.0` is approved,
use normal SemVer rules; update the workflow's pre-1.0 version guard in the reviewed change that authorizes `1.0.0`.

To release:

1. Merge the release changes to `main`, move the completed `Unreleased` entries in `CHANGELOG.md` under `## [VERSION] - YYYY-MM-DD`, and run `composer qa`.
2. From the `main` branch, dispatch the **release** workflow with the version without the `v` prefix (for example, `0.1.0`).
   The workflow verifies the version and changelog entry, creates the `v<version>` Git tag, and publishes a GitHub Release
   using that changelog section as its notes.
3. Confirm the tag/release and its availability on Packagist. Do not create a tag manually; the workflow owns tag creation.

# Contributing

Thank you for helping. Please keep these rules:

1. **No dependencies.** Browser code is plain HTML/CSS/JavaScript (ES modules); server code is plain PHP using only the bundled `openssl` and `session` extensions. No Composer dependencies (`composer.json` only describes the package), no npm packages at runtime, no CDN.
2. **PHP 8.1 to 8.5.** Code must run unchanged on every version; run `php tests/run.php` and `php tests/fuzz.php --strict` on each one you can, and with Node.js every `tests/*_unit.mjs` and `node tests/fuzz.mjs --strict` (see [TESTING](docs/TESTING.md)).
3. **Small, clean code.** No unused variables, functions or files; no temporary files left behind.
4. **Never slower.** Measure before and after any change that touches the screen, input or transport paths, and include the numbers in the pull request.
5. **Tests.** Every critical change comes with a test that fails without it (`tests/`).
6. **Documentation.** Update `README.md`, `README.es.md` and `docs/` in the same pull request.
7. **Security.** Never commit secrets, real host names or personal data.
8. **File header.** Every code file (PHP, JavaScript, CSS, Python) starts with the line `MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0` as a comment.

Miguel Sánchez is the author: the idea of the project is his, he organizes the work and he sets the way of working (the rules above are his). He is assisted by artificial intelligence. See *Authorship and way of working* in the [README](README.md).

Contributions are accepted under the Apache License 2.0, as the rest of the project (section 5 of the [LICENSE](LICENSE)).

Useful references: [architecture](docs/ARCHITECTURE.md), [testing](docs/TESTING.md), [roadmap](docs/ROADMAP.md).

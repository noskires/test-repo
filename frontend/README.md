# Frontend

This project was generated using [Angular CLI](https://github.com/angular/angular-cli) version 21.2.30.

## Development server

To start a local development server, run:

```bash
ng serve
```

Once the server is running, open your browser and navigate to `http://localhost:4200/`. The application will automatically reload whenever you modify any of the source files.

## Design setup (issue 48)

- **Tokens**: colours, radii, shadows and status colours are CSS variables in `src/styles.css`. They were copied as-is from `docs/design/mock-screens/pss-design.css` (dark selectors included). Use the tokens and don't hard-code colours. When the design changes, copy from that file.
- **Icons**: `@lucide/angular` is pinned at exactly `1.71.0` in `package.json`. Don't change the version without human approval.
- **Fonts**: Inter (text) and Geist Mono (IDs) load from Google Fonts in `src/index.html`, so they need internet. Offline, the app falls back to the system fonts.
- **Production build**: `angular.json` sets `optimization.fonts: false` so the build doesn't try to download and inline the Google Fonts CSS. Leave it off unless the fonts are self-hosted.

## Code scaffolding

Angular CLI includes powerful code scaffolding tools. To generate a new component, run:

```bash
ng generate component component-name
```

For a complete list of available schematics (such as `components`, `directives`, or `pipes`), run:

```bash
ng generate --help
```

## Building

To build the project run:

```bash
ng build
```

This will compile your project and store the build artifacts in the `dist/` directory. By default, the production build optimizes your application for performance and speed.

## Running unit tests

To execute unit tests with the [Vitest](https://vitest.dev/) test runner, use the following command:

```bash
ng test
```

## Running end-to-end tests

For end-to-end (e2e) testing, run:

```bash
ng e2e
```

Angular CLI does not come with an end-to-end testing framework by default. You can choose one that suits your needs.

## Additional Resources

For more information on using the Angular CLI, including detailed command references, visit the [Angular CLI Overview and Command Reference](https://angular.dev/tools/cli) page.

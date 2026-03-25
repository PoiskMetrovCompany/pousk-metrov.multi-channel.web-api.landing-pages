<?php
declare(strict_types=1);

/**
 * Swagger UI HTML page.
 *
 * This file is included by `index.php` route GET /docs.
 */

function renderSwaggerUiHtml(string $openApiUrl = '/openapi.json'): string
{
    $openApiUrlEsc = htmlspecialchars($openApiUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return '<!doctype html>
<html lang="ru">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>API Docs</title>
    <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css" />
  </head>
  <body>
    <div id="swagger-ui"></div>
    <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
    <script>
      window.onload = () => {
        SwaggerUIBundle({
          url: "' . $openApiUrlEsc . '",
          dom_id: "#swagger-ui",
        });
      };
    </script>
  </body>
</html>';
}


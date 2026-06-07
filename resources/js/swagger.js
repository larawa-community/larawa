import SwaggerUIBundle from 'swagger-ui-dist/swagger-ui-bundle.js';
import 'swagger-ui-dist/swagger-ui.css';

const mount = document.getElementById('swagger-ui');

if (mount) {
    SwaggerUIBundle({
        url: mount.dataset.openapiUrl,
        dom_id: '#swagger-ui',
        presets: [SwaggerUIBundle.presets.apis],
        layout: 'BaseLayout',
    });
}

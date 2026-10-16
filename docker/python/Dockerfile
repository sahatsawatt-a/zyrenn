# The Python runner (server.mjs): Pyodide -- CPython compiled to WebAssembly --
# run by Node. Pyodide is put in here as the image is built, since the
# container itself has no way out to the internet: what a run may import is
# handed to it by the app (App\Support\Workflow\Code\PythonRunner::install).
FROM node:22-alpine

WORKDIR /opt/runner
RUN npm i --no-audit --no-fund pyodide@0.28.2 \
    && mkdir -p /cache/packages \
    && chown -R node:node /opt/runner /cache

USER node

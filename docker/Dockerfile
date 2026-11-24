# Chat's browser: takes screenshots of pages in broker view (nginx-harness) for the website, which covers
# what the broker would replace before Claude sees them. Playwright's image has Chromium; playwright-core matches it.
FROM mcr.microsoft.com/playwright:v1.64.1-noble

WORKDIR /browser
RUN npm init -y >/dev/null && npm install --omit=dev playwright-core@1.64.1 && npm cache clean --force
COPY server.js .

USER pwuser
EXPOSE 3000
CMD ["node", "server.js"]

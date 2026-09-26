# The image of the ci service in compose.ci.yaml (GUARDRAILS 10). The base is the PHP 8.5 image
# of the v1 channel, the same image .github/workflows/ci.yml uses as its job container, and
# ci-setup.sh is the same setup that workflow runs. ci-entry.sh runs bin/ci on a clean git
# archive of HEAD.
FROM ghcr.io/cboxdk/php-baseimages/php-cli:8.5-bookworm-dev-v1

COPY ci-setup.sh ci-entry.sh /usr/local/lib/cbox-ci/
RUN /usr/local/lib/cbox-ci/ci-setup.sh

ENTRYPOINT ["/usr/local/lib/cbox-ci/ci-entry.sh"]

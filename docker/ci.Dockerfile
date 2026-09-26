# The image of the ci service in compose.ci.yaml (GUARDRAILS 10). The base is the PHP 8.5 image
# of the v1 channel, the same image .github/workflows/ci.yml uses as its job container, and
# nothing is added to it but the entry point: ci-entry.sh archives HEAD and runs HEAD's
# docker/ci-setup.sh and bin/ci, the two steps the workflow runs after its checkout. The setup
# therefore comes from the commit under test, never from the working tree the image was built from.
FROM ghcr.io/cboxdk/php-baseimages/php-cli:8.5-bookworm-dev-v1

COPY ci-entry.sh /usr/local/lib/cbox-ci/

ENTRYPOINT ["/usr/local/lib/cbox-ci/ci-entry.sh"]

ARG SSP_VERSION="v2.5.0"
FROM cirrusid/simplesamlphp:${SSP_VERSION}

# Version of this module to install. "@dev" resolves to the working copy that is
# staged below and registered as a composer path repository.
ARG EMBEDDED_DISCO_VERSION="@dev"

ENV STAGINGCOMPOSERREPOS=embeddeddisco \
    COMPOSER_REQUIRE="simplesamlphp/simplesamlphp-module-embeddeddisco:$EMBEDDED_DISCO_VERSION"

# Stage the working copy so composer can install it from a path repository.
# At runtime docker-compose bind-mounts the host checkout over this same path,
# so edits on the host are picked up without rebuilding.
ADD --chown=www-data:www-data . /var/simplesamlphp/staging-modules/embeddeddisco

# The bind-mounted checkout carries host uid ownership, which makes git (invoked
# by composer for the path repository) refuse to read it.
RUN printf '[safe]\n\tdirectory = /var/simplesamlphp/staging-modules/embeddeddisco\n' >> /etc/gitconfig

# Install as www-data so the runtime pass of ssp-startup.sh (which also runs as
# www-data) can still write composer.json/composer.lock and vendor/.
USER www-data
RUN /opt/simplesaml/staging-install.sh \
    && /opt/simplesaml/module-setup.sh
USER root

ADD docker/ssp/authsources.php /var/simplesamlphp/config/authsources.php
ADD docker/ssp/config-override.php /var/simplesamlphp/config/config-override.php
ADD config-templates/module_embeddeddisco.php /var/simplesamlphp/config/module_embeddeddisco.php

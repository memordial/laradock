ARG MYSQL_VERSION
FROM mysql:${MYSQL_VERSION}

LABEL maintainer="Mahmoud Zalt <mahmoud@zalt.me>"

#####################################
# Set Timezone
#####################################

ARG TZ=UTC
ENV TZ ${TZ}
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone && chown -R mysql:root /var/lib/mysql/

COPY my.cnf /etc/mysql/conf.d/my.cnf

RUN chmod 0444 /etc/mysql/conf.d/my.cnf

# Check if apk or apt-get exists, and install mysql-client accordingly
RUN if command -v apk > /dev/null; then \
  apk update && apk add mysql-client; \
elif command -v apt-get > /dev/null; then \
  apt-get update && apt-get install -y mysql-client; \
fi

EXPOSE 3306

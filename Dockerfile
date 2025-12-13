FROM php:8.3-cli
WORKDIR /opt/4cg/4cg
# COPY . .
CMD ["php", "-S", "0.0.0.0:8080", "-t", "/opt/4cg"]
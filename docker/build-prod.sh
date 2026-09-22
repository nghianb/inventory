#!/usr/bin/env bash
# Build image production và đẩy lên registry.
#
# Build thủ công không có CI đứng sau kiểm, nên rào chắn nằm ở đây: chỉ build từ một commit sạch,
# và tag theo commit sha để rollback được bằng cách đổi tag.
set -euo pipefail

REGISTRY="${REGISTRY:-ghcr.io}"
REPO="${REPO:-nghianb/inventory}"

cd "$(dirname "$0")/.."

if [ -n "$(git status --porcelain)" ]; then
    echo "Cây làm việc không sạch — image sẽ không khớp với commit nào cả:" >&2
    git status --short >&2
    exit 1
fi

sha="$(git rev-parse --short HEAD)"
image="${REGISTRY}/${REPO}"
tag="git-${sha}"

docker build \
    --file docker/php/Dockerfile \
    --target prod \
    --tag "${image}:${tag}" \
    --tag "${image}:latest" \
    --label "org.opencontainers.image.revision=$(git rev-parse HEAD)" \
    --label "org.opencontainers.image.source=https://github.com/${REPO}" \
    .

docker push "${image}:${tag}"
docker push "${image}:latest"

cat <<EOF

Xong. Trên VPS:

    INVENTORY_IMAGE=${image}:${tag} docker compose -f compose.prod.yaml up -d --wait

Rollback: chạy lại đúng lệnh trên với tag cũ.
EOF

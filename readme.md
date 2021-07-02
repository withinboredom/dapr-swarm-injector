# Docker Swarm Injector

blah

```
docker service create \
    --mount type=bind,source=/var/run/docker.sock,target=/var/run/docker.sock \
    --mode global \
    --label swarm.inject=true \
    --name swarm-injector \
    withinboredom/dapr-swarm-injector:v0.2.0
```

```
docker service create \
    --mount type=bind,source=/var/run/docker.sock,target=/var/run/docker.sock \
    --mode replicated \
    --constraint "node.role==manager" \
    --replicas 1 \
    --env INJECT_IMAGE=daprio/daprd:1.2.2 \
    --name swarm-monitor \
    withinboredom/dapr-swarm-monitor:v0.2.0
```

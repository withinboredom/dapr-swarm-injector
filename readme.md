# Docker Swarm Injector

blah

```
docker service create \
    --network none \
    --mount type=bind,source=/var/run/docker.sock,target=/var/run/docker.sock \
    --mode global \
    --label swarm.inject=true \
    --name swarm-injector \
    withinboredom/dapr-swarm-injector:latest
```

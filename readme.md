# Dapr Swarm Injector

TL;DR: Create sidecars in Docker Compose/Docker Swarm

I originally started working on this because I got tired of running into bugs due to only running a single version of a
single service. If I'd had more instances running, I probably would have caught those bugs... probably. At least there
would have been a chance.

Conceptually, sidecars are pretty simple when it comes to Dapr: they only need to share the network space. To make this
easy, I decided to work with Docker Swarm and recreate the sidecar pattern. This isn't Kubernetes, there's no admission
controller ... so we need two services.

## Limitations and Caveats

1. There will be no `DAPR_HTTP_HOST` or `DAPR_GRPC_HOST` environment variable injected. So make sure your app can handle
   that by using the defaults (http: 3500, grpc: 50001).
2. This is a personal project for fun. Please file issues/open PRs if you use this and run into issues!
3. Please don't run this in production. It works ... but I'm not sure what doesn't. yet!
4. These are not sidecars. There will be a few seconds to a few minutes before Dapr exists (especially the first time
   you start a service), so your app needs to be able to wait before crashing.
5. If you're using actors, your service needs to be on the same network as the placement service.
6. I think that's it... oh yeah, please don't run this in production. But if you do, I'd love to hear about it!

## The Injector

This service needs to run on every single node. It monitors local start/stop container events. If it sees a container
start, it looks at the labels on the service/container and starts/removes the sidecar automatically. Since this may or
may not be running on a manager node, it reads a configuration file (`docker config`) from the monitor service. This
configuration file is a map of service id's to labels that the injector can read.

There's not really any direct configuration you can do: just start the service... before starting the monitor!

```
docker service create \
    --mount type=bind,source=/var/run/docker.sock,target=/var/run/docker.sock \
    --mode global \
    --label swarm.inject=true \
    --name swarm-injector \
    docker.io/withinboredom/dapr-swarm-injector:v0.2.0-4392f13
```

## The Monitor

This service needs to run only on manager nodes (only one of them!). It has a lot of configuration options and that gets
passed on to the injector service as required. It watches for service changes, and if it detects a service change, it
updates the configuration file and the injector service, as required.

```
docker service create \
    --mount type=bind,source=/var/run/docker.sock,target=/var/run/docker.sock \
    --mode replicated \
    --constraint "node.role==manager" \
    --replicas 1 \
    --env INJECT_IMAGE=daprio/daprd:1.2.2 \
    --name swarm-monitor \
    docker.io/withinboredom/dapr-swarm-monitor:v0.2.0-4392f13
```

### Environment Variables

- `DOCKER_HOST`: (default: `unix:///var/run/docker.sock`) How to communicate to the Docker host
- `DOCKER_CERT`: (default: `false`) Certificate for secure communications to Docker (can be a secret)
- `INJECT_IMAGE`: (default: `daprio/daprd:edge`) The sidecar image to inject
- `LABEL_PREFIX`: (default: `dapr.io`) Label prefixes to read
- `COMMAND_PREFIX`: (default: `./daprd`) Prefix all commands with this when starting the sidecar
- `INJECTOR_IMAGE`: (default: `withinboredom/dapr-swarm-injector:[current-version]`) The injector image
- `ALWAYS_UPDATE`: (default: `false`) Always update the injector service on startup -- for development of the injector
- `LABEL_MAP_CONFIG`: (default: nothing) Advanced usage that changes the behavior of the injector (see advanced section)
- `COMPONENT_IMAGE`: (default: none) Image to use to extract components to Dapr
- `COMPONENT_PATH`: (default: /components) Path to mount the components at in the daprd image

# Tutorial

## Deploy the injector

First we need to deploy the injector:

```
docker service create \
    --mount type=bind,source=/var/run/docker.sock,target=/var/run/docker.sock \
    --mode global \
    --label swarm.inject=true \
    --name swarm-injector \
    --with-registry-auth \
    withinboredom/dapr-swarm-injector:dev
```

From there, we can constrain it so it doesn't take too much memory/cpu:

```
docker service update swarm-injector --limit-cpu 0.1 --limit-memory 128M
```

### Add Authentication

It's extremely likely you'll run into the Docker Hub pull limits, so you need to make sure you have authenticated
credentials to pull the required images.

1. Create the credentials as a secret:

```
echo '{"username":"withinboredom","password":"password","serveraddress":"https://index.docker.io/v1/"}' | docker secret create docker-auth- -
```

2. Add it to the service

```
docker service update swarm-injector --secret-add docker-auth --env-add DOCKER_AUTH=docker-auth
```

## Deploy the Monitor

```
docker service create \
    --mount type=bind,source=/var/run/docker.sock,target=/var/run/docker.sock \
    --mode replicated \
    --constraint "node.role==manager" \
    --replicas 1 \
    --with-registry-auth \
    --env INJECT_IMAGE=daprio/daprd:1.2.2 \
    --env INJECTOR_IMAGE=withinboredom/dapr-swarm-injector:dev \
    --name swarm-monitor \
    --secret docker-auth-2 \
    --env DOCKER_AUTH=docker-auth-2 \
    withinboredom/dapr-swarm-monitor:dev
```

We can also constrain the CPU and memory:

```
docker service update swarm-monitor --limit-cpu 0.1 --limit-memory 128M
```

## Configuring daprd...

Now we need to configure daprd components, save this in a file:

```yaml
apiVersion: dapr.io/v1alpha1
kind: Configuration
metadata:
  name: appconfig
spec:
  nameResolution:
    component: "dns"
```

Now build an image using config file [see example](example/Dockerfile) and configure the monitor to use that image:

```
docker build -t withinboredom/dapr-example:1.2.2 example/
docker push withinboredom/dapr-example:1.2.2
docker service --env-add INJECT_IMAGE=withinboredom/dapr-example:1.2.2 swarm-monitor --env-add ALWAYS_UPDATE=true
```

Using the `ALWAYS_UPDATE` flag will force it to update the injector with the new configuration, we can set it to false
afterwards.

TODO: Create configuration volume

## Deploying a Dapr Service

Now we can deploy a simple service to Dapr. To do that, we'll deploy a simple overlay network for communication.

```
docker network create --driver overlay --attachable dapr
```

Deploy the placement service for actor support: 

```
docker service create --name placement --with-registry-auth --network dapr --replicas 1 daprio/dapr:1.2.2 ./placement -port 50006
```

A fake service:

```
docker service create \
   --label dapr.io/enabled=true \
   --label dapr.io/config=/dapr-config.yml \
   --label dapr.io/app-id=whoami \
   --label dapr.io/app-port=80 \
   --replicas 5 \
   --network dapr \
   --network consul \
   --name whoami \
   --with-registry-auth \
   traefik/whoami:latest
```

We can verify that it works by creating a one-off container on one of the nodes:

```
container=$(docker create -it --rm \
   --network consul \
   --label dapr.io/enabled=true \
   --label dapr.io/app-id=console \
   --label dapr.io/config=/dapr-config.yml \
   ubuntu:latest bash)
docker network connect dapr $container
docker start $container
docker exec -it $container bash
# apt update && apt install -y curl
# curl localhost:3500/v1.0/invoke/whoami/method/test
```

## Advanced: Changing Injector Behavior

You can create a new JSON config using `docker config create` and passing it using `LABEL_MAP_CONFIG` to configure how
labels are turned into params/props on the image.

Here's the default configuration:

```json
{
  "labels": {
    "app-port": {
      "type": "param"
    },
    "config": {
      "type": "param",
      "required": false
    },
    "app-protocol": {
      "type": "param",
      "default": "http"
    },
    "app-id": {
      "type": "param",
      "default": "%SERVICE_NAME%"
    },
    "enable-profiling": {
      "type": "param",
      "required": false,
      "is_bool": true
    },
    "log-level": {
      "type": "param",
      "default": "info"
    },
    "api-token-secret": {
      "type": "secret",
      "required": false
    },
    "app-token-secret": {
      "type": "secret",
      "required": false
    },
    "log-as-json": {
      "type": "param",
      "required": false,
      "is_bool": true
    },
    "app-max-concurrency": {
      "type": "param",
      "required": false
    },
    "enable-metrics": {
      "type": "param",
      "required": false
    },
    "metrics-port": {
      "type": "param",
      "default": "9090"
    },
    "env": {
      "type": "env",
      "required": false
    },
    "sidecar-cpu-limit": {
      "type": "CPU.Limit",
      "required": false,
      "default": "0"
    },
    "sidecar-cpu-request": {
      "type": "CPU.Request",
      "required": false,
      "default": "0"
    },
    "sidecar-memory-limit": {
      "type": "Memory.Limit",
      "required": false,
      "default": "0"
    },
    "sidecar-memory-request": {
      "type": "Memory.Request",
      "required": false,
      "default": "0"
    },
    "http-max-request-size": {
      "type": "param",
      "as": "-dapr-http-max-request-size",
      "required": false
    },
    "app-ssl": {
      "type": "param",
      "required": false,
      "is_bool": true
    }
  },
  "constants": {
    "mode": {
      "type": "param",
      "value": "standalone"
    },
    "dapr-http-port": {
      "type": "param",
      "value": "3500"
    },
    "dapr-grpc-port": {
      "type": "param",
      "value": "50001"
    },
    "dapr-internal-grpc-port": {
      "type": "param",
      "value": "50002"
    },
    "placement-host-address": {
      "type": "param",
      "value": "placement:50005"
    },
    "restart": {
      "type": "RestartPolicy",
      "value": "unless-stopped"
    },
    "components-path": {
      "type": "param",
      "value": "ENV:COMPONENTS_PATH",
      "default": "/components"
    }
  }
}
```

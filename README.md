# Overview
Simulation of a basic postback delivery system consisting of a front-end ingestion service and a back-end postback
delivery service with decoupled communication between the services provided by [Kafka](http://kafka.apache.org/). This
implementation is not intended to be production ready, but rather serves as a PoC that can be used to kick-the-tires.

Both services as well as Kafka/Zookeeper are delivered using [Docker](https://www.docker.com/) containers and are
orchestrated using [Docker Compose](https://docs.docker.com/compose/). The total solution consists of 4
containers/images:

- The ingestion (PHP) front-end service.
- Kafka broker based on [wurstmeister/kafka](https://hub.docker.com/r/wurstmeister/kafka/)
- Zookeeper based on [wurstmeister/kafka](https://hub.docker.com/r/wurstmeister/kafka/)
- The back-end postback delivery service (Golang).


## Data Flow
1. A web client `POST`s a well-formed JSON object to the ingestion service (see JSON structure in the following
section).
2. The ingestion service validates the JSON object and builds 1 or more postback URLs + methods based on the JSON
object.
3. The ingestion service publishes each postback URL + method to a Kafka topic.
4. The postback delivery service polls Kafka for postback URLs, consuming them 1 at a time by invoking the URL passed
in the message via HTTP.


## Ingestion and JSON Structure
The ingestion service is written in PHP and is responsible for:

- Validating the HTTP method is `POST`. This is the only HTTP method accepted.
- Ensuring the `Content-Type:application/json` header is present. This is the only media type accepted.
- Validating the JSON body of the `POST` request adheres to the accepted structure; validated using
[JSON schema](https://json-schema.org/).
- Building postback methods + URLs from the JSON body. As described below all substitutions in the postback URLs must
be resolved or the request is considered invalid.
- Publishing each of the postback methods + URLs to Kafka; one message per URL. The current wire format of the message
is `<POSTBACK_HTTP_METHOD>,<POSTBACK_HTTP_URL>`.

The format of the JSON object for `POST`ing to the ingest service is shown in the JSON schema below:

```json
{
    "required": [
        "data",
        "endpoint"
    ],
    "type": "object",
    "properties": {
        "endpoint": {
            "required": [
                "method",
                "url"
            ],
            "type": "object",
            "properties": {
                "method": {
                    "type": "string",
                    "example": "GET",
                    "enum": [
                        "GET",
                        "POST"
                    ]
                },
                "url": {
                    "type": "string",
                    "format": "url"
                }
            }
        },
        "data": {
            "type": "array",
            "items": {
                "$ref": "#/definitions/dataitem"
            }
        }
    },
    "definitions": {
        "dataitem": {
            "type": "object",
            "additionalProperties": {
                "type": "string"
            }
        }
    }
}
```

Within the `endpoint.url`, substitution variables can be expressed between brackets. For example, `{var}` used in the
`endpoint.url` indicates `{var}` should be replaced by the value of the `var` key defined in the each of the
`data.[*].var` items of the JSON object. A postback URL + method is built for each object in the `data.[*]` array.

For example, `POST`ing the following JSON object to the ingest service:

```json
{
   "endpoint":{
      "method":"POST",
      "url":"https://postbackdemo.requestcatcher.com?title={mascot}&image={location}"
   },
   "data":[
      {
         "mascot":"Gopher",
         "location":"https://blog.golang.org/gopher/gopher.png"
      },
      {
         "mascot":"Gopher2",
         "location":"https://blog.golang.org/gopher/gopher2.png"
      }
   ]
}
```

Results in the following 2 postbacks being built and sent over the wire (Kafka) to the delivery postback service:

- `POST,https://postbackdemo.requestcatcher.com?title=Gopher&image=https%3A%2F%2Fblog.golang.org%2Fgopher%2Fgopher.png`
- `POST,https://postbackdemo.requestcatcher.com?title=Gopher2&image=https%3A%2F%2Fblog.golang.org%2Fgopher%2Fgopher2.png`


## Postback Delivery
The postback delivery service is written in Golang and is responsible for:

- Consuming postback methods + URLs from a Kafka topic.
- Using a HTTP client to invoke each of the URLs with the given HTTP method.
- Acknowledging each of the messages as they are successfully processed from the Kafka topic. 

The consumption and HTTP callback of the postbacks is done sequentially; one at a time.


## Running the Services
In order to run the simulation, the following prereqs must be installed/met:

- You must have `docker` installed and running on your system.
- You must have `docker-compose` installed and running on your system.
- By default, the following ports are used and must be open: `80`, `9092` and `2181`

To start the services, simply clone this project to your system and from the project directory run
`docker-compose up -d` to orchestrate the system. The following command below does it all in 1 shot:

`git clone git@github.com:bodenr/postback-simulation.git && cd ./postback-simulation && docker-compose up -d`

The first time you run the `docker-compose` command it will take some time to download/build the images used, but
thereafter starting them should be quick. If any of the containers (4 in total) fail to start, you can check their logs
using `docker logs` for the respective container that failed to start.


## Testing the Solution
To test the solution, the following prereqs are needed:

- A HTTP or REST API tool that allows you to `POST` JSON to a web-based API. I prefer to use
[postman](https://www.postman.com/), but there are many others including using `curl` from your command line.
- A means to capture/record the actual postback calls that are performed by the back-end delivery service. There are
various tools/services that can do this for you, but I like [Request Catcher](https://requestcatcher.com/).

Testing the solution:

- First start the containers as described in the previous section and make sure they are all running. You can use
`docker ps` to view the containers and obtain their ID/name.
- Obtain the IP address of the ingest service with the following command where `<INGEST_CONTAINER_ID_OR_NAME>` is the
name or ID of the ingest service container: 
`docker inspect --format='{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' <INGEST_CONTAINER_ID_OR_NAME>`
- Setup the callback catcher by going to [Request Catcher](https://requestcatcher.com/) and enter a unique sub-domain
name of your choice and then click `Get started`. For this example we'll use the sub-domain `postbackdemo`. Leave this
browser window open as it will record each callback in real-time.
- Fire up your REST API testing tool (`postman` or other) and specify:
  - The `POST` HTTP method for the request.
  - The URL to the ingest service endpoint `http://<INGEST_CONTAINER_IP>/ingest.php`. For example if your ingest
  container IP is `172.19.0.4` the URL to specify is `http://172.18.0.4/ingest.php`.
  - The HTTP header `Content-Type:application/json` to indicate you are sending JSON in the body.
  - The JSON object for the request body. Here the `endpoint.url` should have the Request Catcher hostname. For example:
  ```json
  {
     "endpoint":{
        "method":"POST",
        "url":"https://postbackdemo.requestcatcher.com?title={mascot}&image={location}"
     },
     "data":[
        {
           "mascot":"Gopher",
           "location":"https://blog.golang.org/gopher/gopher.png"
        },
        {
           "mascot":"Gopher2",
           "location":"https://blog.golang.org/gopher/gopher2.png"
        }
     ]
  }
  ```
 - Send the request to the ingest service.
 - You should receive a `200` response in your REST API tool used to send the request and shortly thereafter you will
 see the 2 postbacks recorded in the `Request Catcher` window of your browser; one for each of the `data` objects in
 the request JSON.

If you're interested in the time it takes to process the postbacks for each service, you can use `docker logs` on the
ingest and delivery container. Each of them logs the time taken.

For example `docker logs` on the ingest service container shows:

`[Fri Mar 20 14:52:53.296032 2020] [php7:notice] [pid 17] [client 172.18.0.1:36868] Postback complete. 2 postback(s)
in 1.1189181804657 seconds.`

And `docker logs` on the postback service container shows:

`POST,https://postbackdemo.requestcatcher.com?title=Gopher&image=https%3A%2F%2Fblog.golang.org%2Fgopher%2Fgopher.png
complete in 514 ms`

`POST,https://postbackdemo.requestcatcher.com?title=Gopher2&image=https%3A%2F%2Fblog.golang.org%2Fgopher%2Fgopher2.png
complete in 268 ms`


## Debugging Considerations
If you run into issues with the system, it's best to debug each of the services following the data flow outlined in
the earlier section of this document.

### Debugging Ingestion
- If you are getting `4xx` HTTP responses from the ingestion service, ensure you are only using the `POST` HTTP method,
are setting the `Content-Type:application/json` header, and have a well formed JSON object in your request body. The
ingestion service validates all of these and will return `4xx` responses if not met.
- If you are getting `5xx` responses, then it maybe necessary to use `docker log` for the ingestion container to see if
any details are logged.

### Debugging Kafka
- If the ingestion service is returning `200`, but messages are not flowing through the system you may need to dig into
Kafka. A first step would be to uncomment all the `LOG4J_*` env vars in the `docker-compose.yml` for the Kafka container
which enable debug logging for Kafka and restart the solution. Once Kafka is running with debug logging you can use
`docker logs` for the Kafka container to view debug logging.
- If necessary you can view the contents of the `postback` topic from Kafka with the following command:
`docker exec -it <KAFKA_CONTAINER_ID_OR_NAME> /opt/kafka/bin/kafka-console-consumer.sh --bootstrap-server kafka:9092
--topic postback --from-beginning` where `<KAFKA_CONTAINER_ID_OR_NAME>` is the ID or name of the Kafka container. This
will dump any messages in the `postback` topic. Use `CTRL+C` to exit the container when done.

### Debugging Postback
- Use `docker logs` for the postback container to view any failure details.


## Production/Scaling Considerations
As mentioned previously this is a PoC-grade solution as-is. There are a number of considerations in terms of moving
towards a production grade scalable solution; some of them noted below:

- The solution can provide additional scalability/resiliency via horizontal scaling:
  - The ingest service can be run as multiple containers. In this case the standard approach would be to front the
  container-based ingest services with a Load Balancer or API Gateway that can distribute requests across the container
  fleet.
  - The Kafka/Zookeeper services can scale-out by following the approach outlined in
  [wurstmeister/kafka](https://hub.docker.com/r/wurstmeister/kafka/). In this case the `KAFKA_HOSTNAME_PORT` env
  variable for the ingest and postback services (see the `docker-compose.yml`) can be a comma list of the broker:port
  pairs for each Kafka broker.
  - The postback service can be run in multiple containers as-is since this service just listens on the Kafka broker
  topic.
- The solution doesn't have any security today. AuthN/Z, TLS, etc. would be warranted.
- The ingest service written in PHP doesn't directly support termination signals today. In reality the PHP support for
Kafka bindings doesn't have many options today, so a better approach would likely be to re-implement the ingest service
using Golang, Node.js or some other more Cloud Native friendly language/framework.
- The logging in the ingest and delivery services could be improved. For example, timestamps, unique IDs for each
service to better identify end-to-end flows, log levels, etc. That said all services do log to stdout/err following the
12 factor app approach so any log/metric aggregator should be easy to integrate (e.g. Datadog or other).
- The delivery postback service on the back-end currently consumes and processes postbacks 1 at a time. That said,
load testing is warranted and batching could provide better throughput at the expense of some additional handling of
shutdown while batching.
- Additional settings for the ingest and postback services could be exposed via env vars or other. This should be fairly
trivial.
- There are a handful of `TODO`s in the code that could be worth considering.

package main

import (
	"context"
	"fmt"
	"github.com/bodenr/postback/service"
	"os"
	"os/signal"
	"strings"
	"syscall"
	"time"
)

// entry point for the service that configures the kafka and http postback
// as well as sets up and handles SIGTERM and SIGKILL signals.
// It requires KAFKA_HOSTNAME_PORT and KAFKA_POSTBACK_TOPIC
// to be set as env vars to specify Kafka connection details.
func main() {

	kafkaBrokerString, exists := os.LookupEnv("KAFKA_HOSTNAME_PORT")
	if !exists {
		fmt.Println("KAFKA_HOSTNAME_PORT not set in environment")
		os.Exit(1)
	}

	kafkaTopic, exists := os.LookupEnv("KAFKA_POSTBACK_TOPIC")
	if !exists {
		fmt.Println("KAFKA_POSTBACK_TOPIC not set in environment")
		os.Exit(1)
	}

	kafkaConf := service.KafkaDefaults()
	kafkaConf.ConnectionStrings = strings.Split(kafkaBrokerString, ",")
	kafkaConf.Topic = kafkaTopic
	// TODO: support Http timeout, retries, etc. via env
	httpConf := service.HttpDefaults()

	startTime := time.Now().Unix()

	// handle shutdown signals
	termChannel := make(chan os.Signal)
	signal.Notify(termChannel, syscall.SIGTERM, syscall.SIGKILL)

	ctx, cancel := context.WithCancel(context.Background())
	go kafkaConf.Start(ctx, httpConf)

	shutdownSig := <-termChannel
	fmt.Println("Received signal ", shutdownSig)
	cancel() // shutdown processing

	fmt.Println("Shutdown complete. Uptime seconds: ", time.Now().Unix()-startTime)
}

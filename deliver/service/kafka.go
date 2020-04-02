package service

import (
	"context"
	"fmt"
	kafka "github.com/segmentio/kafka-go"
	"strings"
	"time"
)

// fmtLogger is a simple logging adapter for kafka-go that delegates to fmt.Printf
func fmtLogger(msg string, args ...interface{}) {
	fmt.Printf(msg, args...)
	fmt.Println()
}

type KafkaConfig struct {
	ConnectionStrings     []string // <host>:<port>
	Topic                 string
	FetchFailureThreshold int
	MaxConnectAttempts    int
	Logger                kafka.LoggerFunc
}

// build and return a KafkaConfig with sane defaults
func KafkaDefaults() *KafkaConfig {
	return &KafkaConfig{
		ConnectionStrings:     []string{"localhost:9092"},
		Topic:                 "postback",
		FetchFailureThreshold: 4,
		MaxConnectAttempts:    10, // Kafka can take a few seconds to come up
		Logger:                fmtLogger,
	}
}

// build and return a kafka.Reader given a config
func (conf KafkaConfig) NewKafkaReader() *kafka.Reader {
	return kafka.NewReader(kafka.ReaderConfig{
		Brokers:       conf.ConnectionStrings,
		Topic:         conf.Topic,
		MaxAttempts:   conf.MaxConnectAttempts,
		MinBytes:      1,
		MaxBytes:      1024 * 12,
		QueueCapacity: 1,
		Logger:        conf.Logger,
		ErrorLogger:   conf.Logger,
	})
}

// creates a Kafka consumer given a config handles polling and calling postback
// of postback URLs 1 at a time. scale-out should be done by running multiple containers.
// the passed postbackConf is used to configure the HttpPostback  used herein and the
// passed context can be used to cancel the process
func (conf KafkaConfig) Start(ctx context.Context, postbackConf *HttpConfig) {
	fmt.Println("Connecting to Kafka broker(s): " + strings.Join(conf.ConnectionStrings, ", "))
	broker := conf.NewKafkaReader()
	defer broker.Close()

	fmt.Println("Postback service listening on Kafka topic: " + conf.Topic)
	fetchFailureCount := 0

	// fetch postback loop; 1 message at a time
	for {
		// FetchMessage is blocking
		msg, err := broker.FetchMessage(ctx)

		if ctxErr := ctx.Err(); ctxErr != nil {
			fmt.Println("Shutting down service")
			return
		}

		if err != nil {
			fmt.Println("Error fetching message from Kafka broker: " + err.Error())
			fetchFailureCount++

			if fetchFailureCount >= conf.FetchFailureThreshold {
				fmt.Printf("Fetch failure count reached threshold %v aborting agnet\n", fetchFailureCount)
				panic(err)
			}
			// retry fetch
			continue
		}
		fetchFailureCount = 0

		startms := time.Now().UnixNano() / 1000000
		rawPostback := string(msg.Value)

		err = postbackConf.HttpPostback(ctx, rawPostback)
		if err != nil {
			// TODO: should be put into a deadletter or error queue
			fmt.Printf("Postback %s failed and will be discared due to: %v\n", rawPostback, err.Error())
		} else {
			fmt.Printf("%s complete in %v ms\n", rawPostback, time.Now().UnixNano()/1000000-startms)
		}

		broker.CommitMessages(ctx, msg)
	}
}

package service

import (
	"context"
	"fmt"
	"net/http"
	"strings"
	"time"
)

type HttpConfig struct {
	Timeout time.Duration
	Retries int
}

// build and return a HttpConfig with sane defaults
func HttpDefaults() *HttpConfig {
	return &HttpConfig{
		Timeout: time.Second * 10,
		Retries: 3,
	}
}

// perform http postback on the given rawPostback that's in the form of
// <POSTBACK_METHOD>,<POSTBACK_URL> for example GET,http://the.callback.host/a/resource?p=v
// The HttpConfig species the timeout and retries for the postback whereupon
// HTTP response codes from 300 - 499 are retryable whereas >= 500 indicates failure
func (conf HttpConfig) HttpPostback(ctx context.Context, rawPostback string) error {
	postbackSegs := strings.Split(rawPostback, ",")
	postbackMethod := strings.ToUpper(postbackSegs[0])
	postbackChars := []rune(rawPostback)
	postbackUrl := string(postbackChars[len(postbackMethod)+1:])

	for retry := 1; retry <= conf.Retries; retry++ {

		fmt.Printf("Postback(%v/%v) to %v\n", retry, conf.Retries, rawPostback)

		req, err := http.NewRequestWithContext(ctx, postbackMethod, postbackUrl, nil)
		if err != nil {
			return err
		}

		// TODO: consider reusing http client across calls
		client := &http.Client{Timeout: conf.Timeout}
		resp, err := client.Do(req)

		if resp != nil {
			// don't handle body
			resp.Body.Close()
		}

		if ctxErr := ctx.Err(); ctxErr != nil {
			// canceled
			fmt.Println("Canceling HTTP postback for ", rawPostback)
			return ctxErr
		} else if err != nil {
			fmt.Printf("%s postback error: %v\n", rawPostback, err.Error())
			continue
		}

		switch {
		case resp.StatusCode < 300:
			return nil
		case resp.StatusCode < 500:
			return fmt.Errorf("bad HTTP request %v : %v", resp.StatusCode, resp.Status)
		default:
			fmt.Printf("Unexpected HTTP response %v : %v\n", resp.StatusCode, resp.Status)
		}

	}
	return fmt.Errorf("exceeded retries for postback " + rawPostback)
}

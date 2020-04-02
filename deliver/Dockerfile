FROM golang:1.14.0-buster

WORKDIR /go/src/deliver
COPY . .

RUN go get && \
    go build

CMD ["postback"]

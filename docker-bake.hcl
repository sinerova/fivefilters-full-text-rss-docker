variable "IMAGE" {
	default = "ghcr.io/sinerova/fivefilters-full-text-rss"
}

variable "VERSION" {
	default = "3.8.1-hardened"
}

group "default" {
	targets = ["all"]
}

target "all" {
  tags = ["${IMAGE}:latest", "${IMAGE}:${VERSION}"]
  # linux/arm64 was runtime-tested by the maintainer on an ARM64 server (see Readme.md). linux/arm/v7 is excluded.
  platforms = ["linux/amd64", "linux/arm64"]
}

target "amd64" {
	tags = ["${IMAGE}:${VERSION}"]
	platforms = ["linux/amd64"]
}

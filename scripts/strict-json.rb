# frozen_string_literal: true

require 'json'

# Version-independent JSON parsing that rejects duplicate object keys before
# the JSON gem can collapse them. Recent json releases detect duplicates in
# the native parser without routing assignments through object_class#[]=, so a
# custom Hash subclass is not a portable enforcement point.
module StrictJson
  class DuplicateKeyDetector
    WHITESPACE = [0x09, 0x0a, 0x0d, 0x20].freeze

    def initialize(source)
      @source = source
      @index = 0
    end

    def validate!
      skip_whitespace
      scan_value
      true
    end

    private

    def scan_value
      case current_byte
      when 0x7b then scan_object # {
      when 0x5b then scan_array  # [
      when 0x22 then scan_string # "
      else scan_primitive
      end
    end

    def scan_object
      @index += 1
      skip_whitespace
      return @index += 1 if current_byte == 0x7d # }

      seen = {}
      loop do
        raise_parser_error('object key must be a string') unless current_byte == 0x22

        token = scan_string
        key = JSON.parse(token)
        raise_parser_error("duplicate JSON object key #{key.inspect}") if seen.key?(key)

        seen[key] = true
        skip_whitespace
        raise_parser_error("expected ':' after object key") unless current_byte == 0x3a

        @index += 1
        skip_whitespace
        scan_value
        skip_whitespace

        case current_byte
        when 0x2c # ,
          @index += 1
          skip_whitespace
        when 0x7d # }
          @index += 1
          return
        else
          raise_parser_error("expected ',' or '}' after object value")
        end
      end
    end

    def scan_array
      @index += 1
      skip_whitespace
      return @index += 1 if current_byte == 0x5d # ]

      loop do
        scan_value
        skip_whitespace

        case current_byte
        when 0x2c # ,
          @index += 1
          skip_whitespace
        when 0x5d # ]
          @index += 1
          return
        else
          raise_parser_error("expected ',' or ']' after array value")
        end
      end
    end

    def scan_string
      start = @index
      @index += 1

      while (byte = current_byte)
        case byte
        when 0x22 # "
          @index += 1
          return @source.byteslice(start, @index - start)
        when 0x5c # \
          @index += 2
        else
          @index += 1
        end
      end

      raise_parser_error('unterminated string')
    end

    def scan_primitive
      start = @index
      @index += 1 while (byte = current_byte) && !WHITESPACE.include?(byte) && ![0x2c, 0x5d, 0x7d].include?(byte)
      raise_parser_error('expected JSON value') if start == @index
    end

    def skip_whitespace
      @index += 1 while WHITESPACE.include?(current_byte)
    end

    def current_byte
      @source.getbyte(@index)
    end

    def raise_parser_error(message)
      raise JSON::ParserError, "#{message} at byte #{@index}"
    end
  end

  module_function

  def reject_duplicate_keys!(source)
    string = source.respond_to?(:to_str) ? source.to_str : source
    DuplicateKeyDetector.new(string).validate!
    true
  end

  def parse(source, options = {})
    string = source.respond_to?(:to_str) ? source.to_str : source
    reject_duplicate_keys!(string)
    JSON.parse(string, options)
  end

  # The documentation workflow loads this compatibility guard through
  # RUBYOPT. It protects the historical validators without rewriting their
  # hash-bound source bytes, while their existing JSON.parse options continue
  # to control all parsing behavior other than duplicate-key rejection.
  module JsonParseGuard
    def parse(source, *arguments, **keywords, &block)
      StrictJson.reject_duplicate_keys!(source) if source.respond_to?(:to_str)
      super
    end
  end

  def install_json_parse_guard!
    singleton = JSON.singleton_class
    singleton.prepend(JsonParseGuard) unless singleton.ancestors.include?(JsonParseGuard)
  end
end

StrictJson.install_json_parse_guard!

<?xml version="1.0" encoding="UTF-8"?>
<!--
    Convert a xml file "pdf2xml", created via poppler phptohtml, into a standard xml alto (v4).

    Because the source is a basic format (pdf2xml) and because the purpose of conversion is
    to standardize text search and highlighting for IIIF, only basic features of alto are used.
    Anyway, to use a xslt to rebuild a complex layout from a pdf2xml is not an appropriate process.

    @todo Manage pdf2xml bold/italic/link.
    @todo Recreate block/paragraphs and text stream.
    @todo Insert illustrations (useless for word search and highlighting in iiif).

    @see https://gitlab.freedesktop.org/poppler/poppler/-/raw/master/utils/pdf2xml.dtd

    @copyright Daniel Berthereau, 2023
    @license CeCILL 2.1 https://cecill.info/licences/Licence_CeCILL_V2.1-fr.txt

    @version 0.1.0
-->

<xsl:stylesheet version="1.0"
    xmlns:xsl="http://www.w3.org/1999/XSL/Transform"

    xmlns:alto="http://www.loc.gov/standards/alto/ns-v4#"
    xmlns:xlink="http://www.w3.org/1999/xlink"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"

    exclude-result-prefixes="
        xsl alto
        "
    >

    <xsl:output method="xml" encoding="UTF-8" indent="yes"/>

    <xsl:strip-space elements="*"/>

    <!-- Constants. -->

    <xsl:variable name="software_creator">Daniel Berthereau</xsl:variable>
    <xsl:variable name="software_name">pdf2xml_to_alto.xsl</xsl:variable>
    <xsl:variable name="software_version">0.1.0</xsl:variable>

    <!-- Identity template. -->
    <xsl:template match="@*|node()">
        <xsl:copy>
            <xsl:apply-templates select="@*|node()"/>
        </xsl:copy>
    </xsl:template>

    <!-- Root template. -->
    <xsl:template match="/pdf2xml">
        <alto
            xmlns="http://www.loc.gov/standards/alto/ns-v4#"
            xmlns:xlink="http://www.w3.org/1999/xlink"
            xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
            xsi:schemaLocation="http://www.loc.gov/standards/alto/ns-v4# https://www.loc.gov/standards/alto/v4/alto.xsd"
            >
            <Description>
                <MeasurementUnit>pixel</MeasurementUnit>
                <Processing ID="PR_1">
                    <processingStepDescription>Extraction of the text layer from pdf to xml</processingStepDescription>
                    <processingSoftware>
                        <!--
                        <softwareCreator></softwareCreator>
                        -->
                        <softwareName><xsl:value-of select="@producer"/></softwareName>
                        <softwareVersion><xsl:value-of select="@version"/></softwareVersion>
                    </processingSoftware>
                </Processing>
                <Processing ID="PR_2">
                    <processingStepDescription>Conversion from pdf2xml to alto</processingStepDescription>
                    <processingStepSettings>
                        <xsl:text>&#x0A;</xsl:text>
                        <xsl:text>include_text = true</xsl:text>
                        <xsl:text>&#x0A;</xsl:text>
                        <xsl:text>include_fonts = true</xsl:text>
                        <xsl:text>&#x0A;</xsl:text>
                        <xsl:text>include_images = false</xsl:text>
                        <xsl:text>&#x0A;</xsl:text>
                        <xsl:text>include_outline = false</xsl:text>
                        <xsl:text>&#x0A;</xsl:text>
                        <xsl:text>include_bil = false</xsl:text>
                        <xsl:text>&#x0A;</xsl:text>
                    </processingStepSettings>
                    <processingSoftware>
                        <softwareCreator><xsl:value-of select="$software_creator"/></softwareCreator>
                        <softwareName><xsl:value-of select="$software_name"/></softwareName>
                        <softwareVersion><xsl:value-of select="$software_version"/></softwareVersion>
                    </processingSoftware>
                </Processing>
            </Description>
            <xsl:if test="page/fontspec">
                <Styles>
                    <xsl:apply-templates select="page/fontspec"/>
                </Styles>
            </xsl:if>
            <Layout>
                <xsl:apply-templates select="page"/>
            </Layout>
        </alto>
    </xsl:template>

    <xsl:template match="fontspec">
        <!-- Old versions of pdftohtml return bad id and bad size (example: pdftohtml 0.71 on debian 10). -->

        <!-- Convert pdf2xml 0-based id into 1-based alto id. -->
        <!-- Anyway, the id may be badly output, so don't use @id but position(). -->
        <xsl:variable name="textstyle_id" select="concat('TS_', position())"/>

        <xsl:variable name="textstyle_family" select="@family"/>

        <xsl:variable name="textstyle_size">
            <xsl:choose>
                <xsl:when test="normalize-space(@size) = '' or @size + 0 != @size">
                    <xsl:text>10</xsl:text>
                </xsl:when>
                <xsl:otherwise>
                    <xsl:value-of select="@size"/>
                </xsl:otherwise>
            </xsl:choose>
        </xsl:variable>

        <xsl:variable name="textstyle_color">
            <xsl:choose>
                <xsl:when test="normalize-space(@color) = ''">
                    <xsl:text>000000</xsl:text>
                </xsl:when>
                <xsl:when test="substring(@color, 1, 1) = '#'">
                    <xsl:value-of select="substring(@color, 2)"/>
                </xsl:when>
                <xsl:otherwise>
                    <xsl:value-of select="@color"/>
                </xsl:otherwise>
            </xsl:choose>
        </xsl:variable>

        <TextStyle ID="{$textstyle_id}" FONTFAMILY="{$textstyle_family}" FONTSIZE="{$textstyle_size}" FONTCOLOR="{$textstyle_color}"/>
    </xsl:template>

    <xsl:template match="page">
        <!-- TODO Pdf2xml page position is always absolute? -->
        <xsl:variable name="hpos"><xsl:call-template name="min_left"/></xsl:variable>
        <xsl:variable name="vpos"><xsl:call-template name="min_top"/></xsl:variable>
        <xsl:variable name="width"><xsl:call-template name="max_width"/></xsl:variable>
        <xsl:variable name="height"><xsl:call-template name="max_height"/></xsl:variable>
        <!-- TODO Differences between print space and composed block are margins. -->
        <Page WIDTH="{@width}" HEIGHT="{@height}" ID="{concat('PG_', position())}">
            <PrintSpace HPOS="{@left}" VPOS="{@top}" WIDTH="{@width}" HEIGHT="{@height}">
                <ComposedBlock HPOS="{$hpos}" VPOS="{$vpos}" WIDTH="{$width}" HEIGHT="{$height}">
                    <TextBlock HPOS="{$hpos}" VPOS="{$vpos}" WIDTH="{$width}" HEIGHT="{$height}">
                        <xsl:apply-templates select="text"/>
                    </TextBlock>
                </ComposedBlock>
            </PrintSpace>
        </Page>
    </xsl:template>

    <xsl:template match="text">
        <TextLine HPOS="{@left}" VPOS="{@top}" WIDTH="{@width}" HEIGHT="{@height}" STYLEREFS="{concat('TS_', @font + 1)}" >
            <String HPOS="{@left}" VPOS="{@top}" WIDTH="{@width}" HEIGHT="{@height}" CONTENT="{.}"/>
            <!-- TODO Explode and add spaces (SP). -->
        </TextLine>
    </xsl:template>

    <xsl:template name="min_left">
        <xsl:for-each select="@left">
            <xsl:sort select="." data-type="number" order="ascending"/>
            <xsl:if test="position() =1">
                <xsl:value-of select="."/>
            </xsl:if>
        </xsl:for-each>
    </xsl:template>

    <xsl:template name="min_top">
        <xsl:for-each select="@top">
            <xsl:sort select="." data-type="number" order="ascending"/>
            <xsl:if test="position() = 1">
                <xsl:value-of select="."/>
            </xsl:if>
        </xsl:for-each>
    </xsl:template>

    <xsl:template name="max_width">
        <xsl:for-each select="@width">
            <xsl:sort select="." data-type="number" order="descending"/>
            <xsl:if test="position() = 1">
                <xsl:value-of select="."/>
            </xsl:if>
        </xsl:for-each>
    </xsl:template>

    <xsl:template name="max_height">
        <xsl:for-each select="@height">
            <xsl:sort select="." data-type="number" order="descending"/>
            <xsl:if test="position() = 1">
                <xsl:value-of select="."/>
            </xsl:if>
        </xsl:for-each>
    </xsl:template>

</xsl:stylesheet>
